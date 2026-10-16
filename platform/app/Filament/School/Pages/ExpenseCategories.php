<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\ExpenseCategoryController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * FR-DEP-01: the school's expense categories, given with the publisher's six and extended by the
 * school; opened from the expenses. A category is archived, never deleted, and its expenses keep
 * it; `salaries` is the payroll's (AC1): never entered by hand, never archived.
 */
final class ExpenseCategories extends ReferencePage
{
    protected static bool $isDiscovered = true;
    protected static bool $shouldRegisterNavigation = false;
    protected static array $permissions = ['expense.expense.read', 'expense.expense.create', 'expense.expense.validate', 'expense.expense.approve'];
    protected static ?string $section = 'finance';
    protected static ?string $module = 'expenses';
    protected static string|array $routeMiddleware = ['module:expenses', 'permission:expense.expense.read,expense.expense.create,expense.expense.validate,expense.expense.approve'];

    public static function getNavigationLabel(): string { return __('expenses.categories'); }
    public function getTitle(): string { return __('expenses.categories'); }

    public function table(Table $table): Table
    {
        return $table->records(function (?string $search, array $filters, int $page, int $recordsPerPage) {
            $rows = SchoolScreen::call(static::$permissions, ExpenseCategoryController::class, 'index', ['include_archived' => true])['data'];
            return $this->catalogPage($this->filterArchived($rows, $filters), $search, $page, $recordsPerPage);
        })->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->description(__('expenses.categories_help'))->columns([
            TextColumn::make('code')->label(__('school.code'))->searchable()->description(fn (array $record) => $record['system'] ? __('expenses.system') : null),
            TextColumn::make('labels')->label(__('school.name'))->state(fn (array $record) => Locales::label($record['labels']))
                ->description(fn (array $record) => $record['needs_label'] ? __('expenses.needs_label') : null),
            TextColumn::make('sort_order')->label(__('reference.sort_order')),
            TextColumn::make('expenses')->label(__('expenses.count'))->formatStateUsing(fn ($state) => Formats::number((int) $state)),
            TextColumn::make('archived_at')->label(__('school.status'))->state(fn (array $record) => __('reference.archive_states.'.($record['archived_at'] ? 'archived' : 'active'))),
        ])->filters([$this->archiveFilter()])->headerActions([
            Action::make('expenses')->label(__('expenses.expenses'))->color('gray')->url(fn () => Expenses::getUrl()),
            // A school not in use when it received the module has none of the publisher's categories.
            Action::make('defaults')->label(__('expenses.defaults'))->color('gray')->requiresConfirmation()->modalDescription(__('expenses.defaults_help'))
                ->visible(fn () => $this->writable('expense.expense.validate') && app(ExpenseCategoryController::class)->missingDefaults() > 0)
                ->action(fn () => SchoolScreen::change('expense.expense.validate', fn () => SchoolScreen::call('expense.expense.validate', ExpenseCategoryController::class, 'defaults'))),
            Action::make('create')->label(__('expenses.create_category'))->visible(fn () => $this->writable('expense.expense.validate'))
                ->schema([TextInput::make('code')->label(__('school.code'))->required()->regex('/^[a-z0-9_]{1,30}$/')->helperText(__('expenses.category_code')),
                    ...$this->labelFields(), Toggle::make('needs_label')->label(__('expenses.needs_label')), $this->orderField()])
                ->action(fn (array $data) => SchoolScreen::change('expense.expense.validate',
                    fn () => SchoolScreen::call('expense.expense.validate', ExpenseCategoryController::class, 'store', $data))),
        ])->recordActions([
            Action::make('edit')->label(__('school.edit'))->visible(fn (array $record) => !$record['archived_at'] && $this->writable('expense.expense.validate'))
                ->fillForm(fn (array $record) => $record)
                ->schema([...$this->labelFields(), Toggle::make('needs_label')->label(__('expenses.needs_label')), $this->orderField(), Hidden::make('version')->required()])
                ->action(fn (array $record, array $data) => SchoolScreen::change('expense.expense.validate',
                    fn () => SchoolScreen::call('expense.expense.validate', ExpenseCategoryController::class, 'update', $data, ['category' => $record['id']]))),
            Action::make('archive')->label(__('school.archive'))->color('danger')->requiresConfirmation()->modalDescription(__('expenses.archive_category_help'))
                ->visible(fn (array $record) => !$record['archived_at'] && !$record['system'] && $this->writable('expense.expense.validate'))->schema([$this->reason()])
                ->action(fn (array $record, array $data) => SchoolScreen::change('expense.expense.validate',
                    fn () => SchoolScreen::call('expense.expense.validate', ExpenseCategoryController::class, 'archive', $data, ['category' => $record['id']]))),
        ]);
    }
}
