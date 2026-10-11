<?php

namespace App\Filament\Console\Resources;

use App\Filament\Console\Resources\TenantResource\Pages\CreateTenant;
use App\Filament\Console\Resources\TenantResource\Pages\ListTenants;
use App\Models\Console\Tenant;
use App\Support\Console;
use App\Support\DatabaseErrors;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\SupportSessions;
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
    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string { return __('console.tenant.model'); }

    public static function getPluralModelLabel(): string { return __('console.tenant.models'); }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('console.tenant.fields.name'))->required()->maxLength(150),
            TextInput::make('slug')->label(__('console.tenant.fields.slug'))->required()->regex('/^[a-z0-9-]{3,40}$/')
                ->helperText(__('console.tenant.fields.slug_help')),
            Select::make('profile')->label(__('console.tenant.fields.profile'))->required()->options(fn () => self::options('SELECT DISTINCT ON (code) code, labels
                FROM platform.profile WHERE published_at IS NOT NULL ORDER BY code, version DESC')),
            Select::make('plan')->label(__('console.tenant.fields.plan'))->required()->options(fn () => self::options('SELECT code, labels FROM platform.plan ORDER BY code')),
            Select::make('default_locale')->label(__('console.tenant.fields.default_locale'))->required()->default('fr')->options(Locales::NAMES),
            TextInput::make('admin_email')->label(__('console.tenant.fields.admin_email'))->email()->required(),
            TextInput::make('admin_name')->label(__('console.tenant.fields.admin_name'))->required()->maxLength(200),
            DatePicker::make('valid_from')->label(__('console.tenant.fields.valid_from'))->required()->default(now()),
            DatePicker::make('valid_until')->label(__('console.tenant.fields.valid_until')),
            TextInput::make('quota_active_students')->label(__('console.tenant.fields.quota_active_students'))->numeric()->minValue(0)->required()->default(500),
            TextInput::make('quota_storage_mb')->label(__('console.tenant.fields.quota_storage_mb'))->numeric()->minValue(0)->required()->default(1024),
        ]);
    }

    public static function table(Table $table): Table
    {
        $statuses = fn () => array_map(fn ($status) => __('console.tenant.status.'.$status), ['active' => 'active', 'suspended' => 'suspended', 'archived' => 'archived']);
        return $table
            ->query(Tenant::query()->with('entitlement'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('console.tenant.columns.name'))->searchable()->sortable(),
                TextColumn::make('slug')->label(__('console.tenant.columns.slug'))->searchable(),
                TextColumn::make('status')->label(__('console.tenant.columns.status'))->badge()
                    ->formatStateUsing(fn (string $state) => __('console.tenant.status.'.$state))
                    ->color(fn (string $state) => ['active' => 'success', 'suspended' => 'warning', 'archived' => 'gray'][$state]),
                TextColumn::make('entitlement.plan_code')->label(__('console.tenant.columns.plan')),
                TextColumn::make('profile_code')->label(__('console.tenant.columns.profile')),
                TextColumn::make('entitlement.valid_until')->label(__('console.tenant.columns.valid_until'))->formatStateUsing(fn ($state) => Formats::date($state)),
                TextColumn::make('created_at')->label(__('console.tenant.columns.created_at'))->formatStateUsing(fn ($state) => Formats::date($state))->sortable(),
            ])
            ->filters([SelectFilter::make('status')->label(__('console.tenant.columns.status'))->options($statuses)])
            ->recordActions([
                self::statusAction('suspend', 'suspended', fn (Tenant $t) => $t->status === 'active'),
                self::statusAction('reactivate', 'active', fn (Tenant $t) => $t->status === 'suspended'),
                self::statusAction('archive', 'archived', fn (Tenant $t) => $t->status !== 'archived'),
                Action::make('modules')->label(__('console.actions.modules'))->visible(fn (Tenant $t) => $t->status !== 'archived')
                    ->fillForm(fn (Tenant $t) => ['modules' => $t->modules()])
                    ->schema([CheckboxList::make('modules')->label(__('console.actions.modules_enabled'))->columns(2)
                        ->options(fn () => self::options('SELECT key AS code, labels FROM platform.module ORDER BY sort_order, key'))
                        ->helperText(__('console.actions.modules_help'))])
                    ->action(fn (Tenant $t, array $data) => self::attempt(fn () => app(Console::class)->setModules($t->id, $data['modules'] ?? []),
                        __('console.actions.modules_saved'))),
                Action::make('entitlement')->label(__('console.actions.entitlement'))->visible(fn (Tenant $t) => $t->status !== 'archived')
                    ->fillForm(fn (Tenant $t) => [...$t->entitlement->only(['grace_days', 'quota_active_students', 'quota_storage_mb', 'quota_ai_requests_month']),
                        'valid_from' => $t->entitlement->valid_from, 'valid_until' => $t->entitlement->valid_until])
                    ->schema([
                        DatePicker::make('valid_from')->label(__('console.actions.valid_from'))->required(),
                        DatePicker::make('valid_until')->label(__('console.actions.valid_until')),
                        TextInput::make('grace_days')->label(__('console.actions.grace_days'))->numeric()->minValue(0)->maxValue(365)->required(),
                        TextInput::make('quota_active_students')->label(__('console.actions.quota_active_students'))->numeric()->minValue(0)->required(),
                        TextInput::make('quota_storage_mb')->label(__('console.actions.quota_storage_mb'))->numeric()->minValue(0)->required(),
                        TextInput::make('quota_ai_requests_month')->label(__('console.actions.quota_ai_requests_month'))->numeric()->minValue(0)->required(),
                    ])
                    ->action(fn (Tenant $t, array $data) => self::attempt(fn () => app(Console::class)->setEntitlement($t->id, $data),
                        __('console.actions.entitlement_saved'))),
                Action::make('invite')->label(__('console.actions.invite'))->visible(fn (Tenant $t) => $t->status === 'active')
                    ->action(fn (Tenant $t) => self::attempt(function () use ($t) {
                        $sent = app(Console::class)->inviteAdmin($t->id);
                        if ($sent['delivery'] === 'failed') {
                            Notification::make()->title(__('console.actions.invite_not_sent'))->body($sent['activation_url'])->warning()->persistent()->send();
                            return null;
                        }
                        return __('console.actions.invite_sent');
                    })),
                Action::make('support')->label(__('console.actions.support'))->visible(fn (Tenant $t) => $t->status !== 'suspended')
                    ->modalDescription(fn (Tenant $t) => self::grants($t))
                    ->modalSubmitActionLabel(__('console.actions.support_open'))
                    ->action(function (Tenant $t) {
                        $grant = collect(app(Console::class)->supportGrants($t->id))
                            ->first(fn ($g) => $g->revoked_at === null && strtotime($g->starts_at) <= time() && strtotime($g->expires_at) > time());
                        if (!$grant || !app(SupportSessions::class)->open($t->id, $grant->id)) {
                            Notification::make()->title(__('errors.support_none'))->danger()->send();
                            return;
                        }
                        $opened = $grant->include_sensitive ? 'console.actions.support_opened_sensitive' : 'console.actions.support_opened';
                        Notification::make()->title(__($opened, ['until' => Formats::dateTime($grant->expires_at)]))->success()->send();
                    }),
                Action::make('profile')->label(__('console.actions.profile'))->visible(fn (Tenant $t) => $t->status === 'active')
                    ->fillForm(fn (Tenant $t) => ['profile' => $t->profile_code])
                    ->schema([Select::make('profile')->label(__('console.actions.profile_field'))->required()
                        ->options(fn () => self::options('SELECT DISTINCT ON (code) code, labels FROM platform.profile WHERE published_at IS NOT NULL ORDER BY code, version DESC'))
                        ->helperText(__('console.actions.profile_help'))])
                    ->action(fn (Tenant $t, array $data) => self::attempt(fn () => __('console.actions.profile_applied',
                        ['version' => app(Console::class)->applyProfile($t->id, $data['profile'])]))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListTenants::route('/'), 'create' => CreateTenant::route('/create')];
    }

    private static function statusAction(string $name, string $status, \Closure $visible): Action
    {
        return Action::make($name)->label(__('console.actions.'.$name))->visible($visible)->requiresConfirmation()->color($status === 'active' ? 'success' : 'danger')
            ->modalDescription($status === 'archived' ? __('console.actions.archive_warning') : null)
            ->schema([Textarea::make('reason')->label(__('console.actions.reason'))->required()->minLength(3)->maxLength(1000)])
            ->action(fn (Tenant $t, array $data) => self::attempt(fn () => app(Console::class)->setStatus($t->id, $status, $data['reason']),
                __('console.actions.status_saved')));
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

    /** The school's consents, as the operator sees them before opening an access. */
    private static function grants(Tenant $tenant): string
    {
        $lines = array_map(fn ($g) => __('console.actions.support_grant', ['from' => Formats::dateTime($g->starts_at), 'until' => Formats::dateTime($g->expires_at),
            'author' => $g->granted_by ?? '?', 'reason' => $g->reason, 'count' => $g->consultations])
            .($g->revoked_at ? ' · '.__('console.actions.support_revoked') : ''), app(Console::class)->supportGrants($tenant->id));
        return $lines ? implode("\n", $lines) : __('console.actions.support_no_grant');
    }

    /** @return array<string, string> codes and their labels in the current language */
    private static function options(string $sql): array
    {
        return collect(DB::connection('console')->select($sql))->mapWithKeys(fn ($row) => [$row->code => Locales::label($row->labels) ?? $row->code])->all();
    }
}
