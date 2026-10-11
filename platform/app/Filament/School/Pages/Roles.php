<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\PermissionController;
use App\Http\Controllers\RoleController;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;

final class Roles extends SchoolPage implements HasTable
{
    use InteractsWithTable;

    protected static bool $isDiscovered = true;
    protected static ?string $section = 'people';
    protected static array $permissions = ['role.definition.manage'];
    protected static ?int $navigationSort = 20;
    protected static string|array $routeMiddleware = 'permission:role.definition.manage';
    protected string $view = 'filament.school.table';

    public static function getNavigationLabel(): string { return __('school.roles'); }
    public function getTitle(): string { return __('school.roles'); }

    public function table(Table $table): Table
    {
        return $table->records(function (?string $search, int $page, int $recordsPerPage) {
            $rows = collect(SchoolScreen::call('role.definition.manage', RoleController::class, 'index')['data'])->keyBy('id');
            if ($search) $rows = $rows->filter(fn ($row) => str_contains(mb_strtolower($row['code'].' '.implode(' ', $row['labels'])), mb_strtolower($search)));
            return new LengthAwarePaginator($rows->forPage($page, $recordsPerPage), $rows->count(), $recordsPerPage, $page);
        })->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->columns([
            TextColumn::make('code')->label(__('school.code'))->searchable(),
            TextColumn::make('labels')->label(__('school.name'))->state(fn (array $record) => Locales::label($record['labels'])),
        ])->headerActions([
            Action::make('create')->label(__('school.create_role'))->visible(fn () => $this->writable('role.definition.manage'))
                ->schema([TextInput::make('code')->label(__('school.code'))->required()->regex('/^[a-z][a-z0-9_]{1,39}$/')->helperText(__('school.stable_code')),
                    ...$this->labels(), Select::make('copy_from')->label(__('school.copy_role'))->options(fn () => $this->roleOptions())])
                ->action(fn (array $data) => SchoolScreen::change('role.definition.manage', fn () => SchoolScreen::call('role.definition.manage', RoleController::class, 'store', $data))),
        ])->recordActions([
            Action::make('edit')->label(__('school.edit'))->visible(fn () => $this->writable('role.definition.manage'))
                ->fillForm(fn (array $record) => $record)->schema([...$this->labels(), Hidden::make('version')->required(),
                    CheckboxList::make('permissions')->label(__('school.permissions'))->options(fn () => $this->permissionOptions())->searchable()->columns(2)])
                ->action(fn (array $record, array $data) => SchoolScreen::change('role.definition.manage', function () use ($record, $data) {
                    SchoolScreen::call('role.definition.manage', RoleController::class, 'update', $data, ['role' => $record['id']]);
                    return SchoolScreen::call('role.definition.manage', RoleController::class, 'permissions', ['permissions' => $data['permissions'] ?? []], ['role' => $record['id']]);
                })),
            Action::make('archive')->label(__('school.archive'))->color('danger')->requiresConfirmation()
                ->visible(fn () => $this->writable('role.definition.manage'))->schema([$this->reason()])
                ->action(fn (array $record, array $data) => SchoolScreen::change('role.definition.manage',
                    fn () => SchoolScreen::call('role.definition.manage', RoleController::class, 'archive', $data, ['role' => $record['id']]))),
        ]);
    }

    private function labels(): array
    {
        return [TextInput::make('labels.fr')->label(__('school.label_fr'))->required()->maxLength(150),
            TextInput::make('labels.ar')->label(__('school.label_ar'))->maxLength(150)->extraInputAttributes(['dir' => 'rtl'])];
    }

    private function roleOptions(): array
    {
        return collect(SchoolScreen::call('role.definition.manage', RoleController::class, 'index')['data'])
            ->filter(fn ($row) => $row['permissions'] !== [])
            ->mapWithKeys(fn ($row) => [$row['id'] => Locales::label($row['labels']) ?? $row['code']])->all();
    }

    private function permissionOptions(): array
    {
        return collect(SchoolScreen::call('role.definition.manage', PermissionController::class, 'index')['data'])->mapWithKeys(fn ($row) => [
            $row['code'] => (Locales::label($row['labels']) ?? $row['code']).($row['sensitive'] ? ' · '.__('school.sensitive') : ''),
        ])->all();
    }
}
