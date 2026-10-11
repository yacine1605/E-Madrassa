<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\RoleAssignmentController;
use App\Http\Controllers\RoleController;
use App\Support\CurrentSchool;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

final class Assignments extends SchoolPage implements HasTable
{
    use InteractsWithTable;

    protected static bool $isDiscovered = true;
    protected static ?string $section = 'people';
    protected static array $permissions = ['role.assignment.manage'];
    protected static ?int $navigationSort = 30;
    protected static string|array $routeMiddleware = 'permission:role.assignment.manage';
    protected string $view = 'filament.school.table';

    public static function getNavigationLabel(): string { return __('school.assignments'); }
    public function getTitle(): string { return __('school.assignments'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (?string $search, int $page, int $recordsPerPage) => $this->apiPage('role.assignment.manage',
            RoleAssignmentController::class, 'index', array_filter(['q' => $search]), $page, $recordsPerPage))->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->columns([
            TextColumn::make('user_name')->label(__('school.name'))->searchable(),
            TextColumn::make('role_labels')->label(__('school.role'))->state(fn (array $record) => Locales::label($record['role_labels'])),
            TextColumn::make('scope_type')->label(__('school.scope'))->formatStateUsing(fn (string $state) => __('school.scopes.'.$state)),
            TextColumn::make('scope_ids')->label(__('school.scope_targets'))->state(fn (array $record) => $this->scopeLabels($record)),
            TextColumn::make('valid_from')->label(__('school.valid_from'))->formatStateUsing(fn ($state) => Formats::date($state)),
            TextColumn::make('valid_until')->label(__('school.valid_until'))->formatStateUsing(fn ($state) => Formats::date($state)),
        ])->headerActions([
            Action::make('create')->label(__('school.create_assignment'))->visible(fn () => $this->writable('role.assignment.manage'))
                ->schema([
                    Select::make('user_id')->label(__('school.account'))->required()->searchable()->getSearchResultsUsing(fn (string $search) => $this->memberOptions($search))
                        ->getOptionLabelUsing(fn ($value) => $this->memberLabel((int) $value)),
                    Select::make('role_id')->label(__('school.role'))->required()->options(fn () => collect(SchoolScreen::call('role.assignment.manage', RoleController::class, 'index')['data'])
                        ->filter(fn ($r) => $r['permissions'] !== [])
                        ->mapWithKeys(fn ($r) => [$r['id'] => Locales::label($r['labels']) ?? $r['code']])->all()),
                    Select::make('scope_type')->label(__('school.scope'))->required()->default('tenant')->options(__('school.scopes'))->live()
                        ->afterStateUpdated(fn (Set $set) => $set('scope_ids', [])),
                    Select::make('scope_ids')->label(__('school.scope_targets'))->multiple()->searchable()->maxItems(100)
                        ->visible(fn (Get $get) => in_array($get('scope_type'), ['site', 'level', 'subject'], true))
                        ->required(fn (Get $get) => in_array($get('scope_type'), ['site', 'level', 'subject'], true))
                        ->options(fn (Get $get) => $this->scopeOptions($get('scope_type'))),
                    DatePicker::make('valid_from')->label(__('school.valid_from'))->required()->default(now()),
                    DatePicker::make('valid_until')->label(__('school.valid_until'))->afterOrEqual('valid_from'),
                ])->action(function (array $data) {
                    if (in_array($data['scope_type'], ['tenant', 'personal'], true)) unset($data['scope_ids']);
                    SchoolScreen::change('role.assignment.manage', fn () => SchoolScreen::call('role.assignment.manage', RoleAssignmentController::class, 'store', $data));
                }),
        ])->recordActions([
            Action::make('archive')->label(__('school.end_assignment'))->requiresConfirmation()->color('danger')
                ->visible(fn (array $record) => $record['user_id'] !== auth()->id() && $this->writable('role.assignment.manage'))
                ->schema([$this->reason()])->action(fn (array $record, array $data) => SchoolScreen::change('role.assignment.manage',
                    fn () => SchoolScreen::call('role.assignment.manage', RoleAssignmentController::class, 'archive', $data, ['assignment' => $record['id']]))),
        ]);
    }

    /** Assignment managers need member identities even without the separate account-reading permission. */
    private function memberOptions(string $search): array
    {
        return collect(DB::select("SELECT u.id, u.name, m.username FROM school.membership m JOIN public.users u ON u.id = m.user_id
            WHERE m.tenant_id = ? AND m.status IN ('active', 'invited') AND m.user_id <> ?
            AND position(coalesce(school.search_key(?), '') IN coalesce(school.search_key(concat_ws(' ', u.name, u.email, m.username)), '')) > 0
            ORDER BY u.name, u.id LIMIT 50", [app(CurrentSchool::class)->id(), auth()->id(), $search]))
            ->mapWithKeys(fn ($r) => [$r->id => $r->name.($r->username ? ' · '.$r->username : '')])->all();
    }

    private function memberLabel(int $id): ?string
    {
        return DB::selectOne('SELECT u.name FROM school.membership m JOIN public.users u ON u.id = m.user_id WHERE m.tenant_id = ? AND m.user_id = ?',
            [app(CurrentSchool::class)->id(), $id])?->name;
    }

    private function scopeOptions(?string $scope, bool $archived = false): array
    {
        $table = ['site' => 'site', 'level' => 'ref_node', 'subject' => 'subject'][$scope ?? ''] ?? null;
        if (!$table) return [];
        return collect(DB::select('SELECT id, labels FROM school.'.$table.' WHERE tenant_id = ?'.($archived ? '' : ' AND archived_at IS NULL').' ORDER BY id',
            [app(CurrentSchool::class)->id()]))->mapWithKeys(fn ($r) => [$r->id => Locales::label($r->labels) ?? $r->id])->all();
    }

    private function scopeLabels(array $record): string
    {
        $options = $this->scopeOptions($record['scope_type'], true);
        return Formats::list(array_map(fn ($id) => $options[$id] ?? $id, $record['scope_ids'] ?? []));
    }
}
