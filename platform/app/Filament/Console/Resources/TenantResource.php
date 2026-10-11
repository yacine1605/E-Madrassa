<?php

namespace App\Filament\Console\Resources;

use App\Filament\Console\Resources\TenantResource\Pages\CreateTenant;
use App\Filament\Console\Resources\TenantResource\Pages\ListTenants;
use App\Models\Console\Tenant;
use App\Support\Console;
use App\Support\DatabaseErrors;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The schools (FR-PLT-01, FR-PLT-02, FR-PLT-03): created with their profile, plan and first
 * administrator; suspended, reactivated or archived with a reason; their modules, subscription
 * and profile. The console reads platform data only.
 */
final class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;
    protected static ?string $modelLabel = 'établissement';
    protected static ?string $pluralModelLabel = 'établissements';
    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nom')->required()->maxLength(150),
            TextInput::make('slug')->label('Identifiant court')->required()->regex('/^[a-z0-9-]{3,40}$/')
                ->helperText('Minuscules, chiffres et tirets ; définitif.'),
            Select::make('profile')->label('Profil')->required()->options(fn () => self::options('SELECT DISTINCT ON (code) code, labels ->> \'fr\' AS label
                FROM platform.profile WHERE published_at IS NOT NULL ORDER BY code, version DESC')),
            Select::make('plan')->label('Plan')->required()->options(fn () => self::options("SELECT code, labels ->> 'fr' AS label FROM platform.plan ORDER BY code")),
            Select::make('default_locale')->label('Langue par défaut')->required()->default('fr')->options(['fr' => 'Français', 'ar' => 'العربية', 'en' => 'English']),
            TextInput::make('admin_email')->label('E-mail du premier administrateur')->email()->required(),
            TextInput::make('admin_name')->label('Nom du premier administrateur')->required()->maxLength(200),
            DatePicker::make('valid_from')->label('Début de l’abonnement')->required()->default(now()),
            DatePicker::make('valid_until')->label('Fin de l’abonnement'),
            TextInput::make('quota_active_students')->label('Élèves actifs (quota)')->numeric()->minValue(0)->required()->default(500),
            TextInput::make('quota_storage_mb')->label('Stockage (Mo)')->numeric()->minValue(0)->required()->default(1024),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(Tenant::query()->with('entitlement'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Nom')->searchable()->sortable(),
                TextColumn::make('slug')->label('Identifiant')->searchable(),
                TextColumn::make('status')->label('État')->badge()
                    ->formatStateUsing(fn (string $state) => ['active' => 'actif', 'suspended' => 'suspendu', 'archived' => 'archivé'][$state])
                    ->color(fn (string $state) => ['active' => 'success', 'suspended' => 'warning', 'archived' => 'gray'][$state]),
                TextColumn::make('entitlement.plan_code')->label('Plan'),
                TextColumn::make('profile_code')->label('Profil'),
                TextColumn::make('entitlement.valid_until')->label('Fin d’abonnement')->date('d/m/Y'),
                TextColumn::make('created_at')->label('Créé le')->date('d/m/Y')->sortable(),
            ])
            ->filters([SelectFilter::make('status')->label('État')->options(['active' => 'actif', 'suspended' => 'suspendu', 'archived' => 'archivé'])])
            ->recordActions([
                self::statusAction('suspend', 'Suspendre', 'suspended', fn (Tenant $t) => $t->status === 'active'),
                self::statusAction('reactivate', 'Réactiver', 'active', fn (Tenant $t) => $t->status === 'suspended'),
                self::statusAction('archive', 'Archiver', 'archived', fn (Tenant $t) => $t->status !== 'archived'),
                Action::make('modules')->label('Modules')->visible(fn (Tenant $t) => $t->status !== 'archived')
                    ->fillForm(fn (Tenant $t) => ['modules' => $t->modules()])
                    ->schema([CheckboxList::make('modules')->label('Modules activés')->columns(2)
                        ->options(fn () => self::options("SELECT key AS code, labels ->> 'fr' AS label FROM platform.module ORDER BY sort_order, key"))
                        ->helperText('Les modules requis par un module activé le sont aussi ; « core » reste toujours actif.')])
                    ->action(fn (Tenant $t, array $data) => self::attempt(fn () => app(Console::class)->setModules($t->id, $data['modules'] ?? []), 'Modules enregistrés.')),
                Action::make('entitlement')->label('Abonnement')->visible(fn (Tenant $t) => $t->status !== 'archived')
                    ->fillForm(fn (Tenant $t) => [...$t->entitlement->only(['grace_days', 'quota_active_students', 'quota_storage_mb', 'quota_ai_requests_month']),
                        'valid_from' => $t->entitlement->valid_from, 'valid_until' => $t->entitlement->valid_until])
                    ->schema([
                        DatePicker::make('valid_from')->label('Début')->required(), DatePicker::make('valid_until')->label('Fin'),
                        TextInput::make('grace_days')->label('Délai de grâce (jours)')->numeric()->minValue(0)->maxValue(365)->required(),
                        TextInput::make('quota_active_students')->label('Élèves actifs')->numeric()->minValue(0)->required(),
                        TextInput::make('quota_storage_mb')->label('Stockage (Mo)')->numeric()->minValue(0)->required(),
                        TextInput::make('quota_ai_requests_month')->label('Requêtes d’IA par mois')->numeric()->minValue(0)->required(),
                    ])
                    ->action(fn (Tenant $t, array $data) => self::attempt(fn () => app(Console::class)->setEntitlement($t->id, $data), 'Abonnement enregistré.')),
                Action::make('invite')->label('Renvoyer l’invitation')->visible(fn (Tenant $t) => $t->status === 'active')
                    ->action(fn (Tenant $t) => self::attempt(function () use ($t) {
                        $sent = app(Console::class)->inviteAdmin($t->id);
                        if ($sent['delivery'] === 'failed') {
                            Notification::make()->title('Envoi impossible : transmettez ce lien de façon sûre, valable 72 heures.')
                                ->body($sent['activation_url'])->warning()->persistent()->send();
                            return null;
                        }
                        return 'Invitation envoyée (valable 72 heures).';
                    })),
                Action::make('profile')->label('Appliquer un profil')->visible(fn (Tenant $t) => $t->status === 'active')
                    ->fillForm(fn (Tenant $t) => ['profile' => $t->profile_code])
                    ->schema([Select::make('profile')->label('Profil')->required()
                        ->options(fn () => self::options('SELECT DISTINCT ON (code) code, labels ->> \'fr\' AS label FROM platform.profile WHERE published_at IS NOT NULL ORDER BY code, version DESC'))
                        ->helperText('Ajoute ce qui manque à l’établissement ; ne modifie ni ne réactive rien.')])
                    ->action(fn (Tenant $t, array $data) => self::attempt(fn () => 'Profil appliqué (version '.app(Console::class)->applyProfile($t->id, $data['profile']).').')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListTenants::route('/'), 'create' => CreateTenant::route('/create')];
    }

    private static function statusAction(string $name, string $label, string $status, \Closure $visible): Action
    {
        return Action::make($name)->label($label)->visible($visible)->requiresConfirmation()->color($status === 'active' ? 'success' : 'danger')
            ->modalDescription($status === 'archived' ? 'Un établissement archivé reste en lecture seule pour son export final ; c’est définitif.' : null)
            ->schema([Textarea::make('reason')->label('Motif (journal de l’établissement)')->required()->minLength(3)->maxLength(1000)])
            ->action(fn (Tenant $t, array $data) => self::attempt(fn () => app(Console::class)->setStatus($t->id, $status, $data['reason']), 'État enregistré.'));
    }

    /** Runs a change; a refusal of the database becomes a notification in plain words. */
    public static function attempt(callable $change, ?string $success = null): void
    {
        try {
            $message = $change();
            if (($message = is_string($message) ? $message : $success) !== null) Notification::make()->title($message)->success()->send();
        } catch (QueryException $e) {
            $refusal = DatabaseErrors::classify($e) ?? throw $e;
            Notification::make()->title($refusal[2])->danger()->send();
        }
    }

    /** @return array<string, string> */
    private static function options(string $sql): array
    {
        return collect(DB::connection('console')->select($sql))->mapWithKeys(fn ($row) => [$row->code => $row->label ?? $row->code])->all();
    }
}
