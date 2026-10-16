<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\ExpenseController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\Permissions;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-DEP-02: the school's expenses, the latest first, by state, category, period, beneficiary. A
 * draft is corrected, documented and submitted by its author; then validated or rejected by
 * someone else (AC1), by the direction beyond the school's threshold; decided, it never changes
 * (AC2). Its documents only for `expense.attachment.read` (AC3).
 */
final class Expenses extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 12;
    protected static ?string $section = 'finance';
    protected static array $permissions = ['expense.expense.read', 'expense.expense.create', 'expense.expense.validate', 'expense.expense.approve'];
    protected static ?string $module = 'expenses';
    protected static string|array $routeMiddleware = ['module:expenses', 'permission:expense.expense.read,expense.expense.create,expense.expense.validate,expense.expense.approve'];
    private ?array $categoryRows = null;

    public static function getNavigationLabel(): string { return __('expenses.expenses'); }
    public function getTitle(): string { return __('expenses.expenses'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (?string $search, array $filters, int $page, int $recordsPerPage) => $this->apiPage(static::$permissions, ExpenseController::class, 'index',
            $this->query($search, $filters), $page, $recordsPerPage))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->description(__('expenses.expenses_help'))
            ->emptyStateHeading(__('expenses.no_expense'))
            ->columns([
                TextColumn::make('expense_date')->label(__('expenses.date'))->formatStateUsing(fn ($state) => Formats::date($state))
                    ->description(fn (array $record) => Locales::label($record['category_labels'])),
                TextColumn::make('beneficiary')->label(__('expenses.beneficiary'))->searchable()->formatStateUsing(fn ($state) => Formats::isolated($state))
                    ->description(fn (array $record) => Formats::isolated(Formats::list(array_filter([$record['label'], $record['reason'], $record['program']])))),
                TextColumn::make('amount')->label(__('expenses.amount'))->formatStateUsing(fn ($state) => $this->money($state)),
                TextColumn::make('status')->label(__('expenses.status'))->badge()->formatStateUsing(fn (string $state) => __('expenses.states.'.$state))
                    ->color(fn (string $state) => match ($state) { 'validated' => 'success', 'rejected' => 'danger', 'submitted' => 'warning', default => 'gray' })
                    ->description(fn (array $record) => Formats::list(array_filter([$record['needs_approval'] ? __('expenses.needs_approval') : null,
                        $record['decision_reason'] ? Formats::isolated($record['decision_reason']) : null]))),
                TextColumn::make('author')->label(__('expenses.by'))->formatStateUsing(fn ($state) => Formats::isolated((string) $state))
                    ->description(fn (array $record) => $record['decider'] ? Formats::isolated($record['decider']) : null),
            ])->filters([
                SelectFilter::make('status')->label(__('expenses.status'))->options(__('expenses.states')),
                SelectFilter::make('category')->label(__('expenses.category'))->options(fn () => collect($this->categories(true))
                    ->mapWithKeys(fn (array $row) => [$row['id'] => Locales::label($row['labels'])])->all()),
                Filter::make('period')->schema([DatePicker::make('from')->label(__('expenses.from')), DatePicker::make('to')->label(__('expenses.to'))]),
            ])->headerActions([
                Action::make('categories')->label(__('expenses.categories'))->color('gray')->url(fn () => ExpenseCategories::getUrl()),
                Action::make('create')->label(__('expenses.create'))->visible(fn () => $this->writable('expense.expense.create'))
                    ->fillForm(fn () => ['expense_date' => now()->toDateString(), 'idempotency_key' => (string) Str::uuid()])
                    ->schema(fn () => [...$this->fields(), Hidden::make('idempotency_key')])
                    ->action(fn (array $data) => SchoolScreen::change('expense.expense.create', fn () => SchoolScreen::call('expense.expense.create',
                        ExpenseController::class, 'store', [...$this->sent($data), 'idempotency_key' => $data['idempotency_key']]))),
            ])->recordActions([ActionGroup::make([
                Action::make('edit')->label(__('expenses.edit'))->visible(fn (array $record) => $this->own($record, 'draft'))
                    ->fillForm(fn (array $record) => [...$record, 'amount' => number_format((float) $record['amount'], 2, ',', ' ')])
                    ->schema(fn () => [...$this->fields(), Hidden::make('version')->required()])
                    ->action(fn (array $record, array $data) => SchoolScreen::change('expense.expense.create', fn () => SchoolScreen::call('expense.expense.create',
                        ExpenseController::class, 'update', [...$this->sent($data), 'version' => (int) $data['version']], ['expense' => $record['id']]))),
                Action::make('attach')->label(__('expenses.attach'))->visible(fn (array $record) => $this->own($record, 'draft'))
                    // No preview: the browser would draw it in a worker the Content Security Policy refuses.
                    ->schema([FileUpload::make('attachment')->label(__('expenses.attachment'))->storeFiles(false)->previewable(false)->required()
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])->maxSize(10240)])
                    ->action(fn (array $record, array $data) => SchoolScreen::change('expense.expense.create', fn () => SchoolScreen::call('expense.expense.create',
                        ExpenseController::class, 'attach', ['attachment' => $data['attachment']], ['expense' => $record['id']]))),
                Action::make('submit')->label(__('expenses.submit'))->requiresConfirmation()->modalDescription(__('expenses.submit_help'))
                    ->visible(fn (array $record) => $this->own($record, 'draft'))
                    ->action(fn (array $record) => SchoolScreen::change('expense.expense.create', fn () => SchoolScreen::call('expense.expense.create',
                        ExpenseController::class, 'submit', ['version' => $record['version']], ['expense' => $record['id']]))),
                // AC1: by someone else than its author; beyond the threshold, the direction.
                Action::make('validate')->label(__('expenses.validate'))->requiresConfirmation()->visible(fn (array $record) => $this->decidable($record))
                    ->action(fn (array $record) => SchoolScreen::change($this->decider($record), fn () => SchoolScreen::call($this->decider($record),
                        ExpenseController::class, 'validateExpense', ['version' => $record['version']], ['expense' => $record['id']]))),
                Action::make('reject')->label(__('expenses.reject'))->color('danger')->visible(fn (array $record) => $this->decidable($record))
                    ->schema([Textarea::make('reason')->label(__('expenses.reason'))->required()->minLength(3)->maxLength(500)])
                    ->action(fn (array $record, array $data) => SchoolScreen::change($this->decider($record), fn () => SchoolScreen::call($this->decider($record),
                        ExpenseController::class, 'reject', ['version' => $record['version'], 'reason' => $data['reason']], ['expense' => $record['id']]))),
                // AC3: its documents, for `expense.attachment.read` only.
                Action::make('documents')->label(__('expenses.documents'))->modalSubmitAction(false)
                    ->visible(fn () => app(Permissions::class)->can('expense.attachment.read'))
                    ->schema(fn (array $record) => [RepeatableEntry::make('documents')->hiddenLabel()->placeholder(__('expenses.no_document'))
                        ->state(array_map(fn (array $file) => ['name' => $file['original_name'], 'link' => url('/api/expenses/'.$record['id'].'/attachments/'.$file['id'])],
                            $this->documents($record['id'])))
                        ->columns(2)->schema([TextEntry::make('name')->hiddenLabel(),
                            TextEntry::make('link')->hiddenLabel()->formatStateUsing(fn () => __('finance.open_attachment'))
                                ->url(fn (?string $state) => $state, shouldOpenInNewTab: true)->color('primary')])]),
            ])]);
    }

    private function query(?string $search, array $filters): array
    {
        $period = $filters['period'] ?? [];
        return array_filter(['search' => $search ?: null, 'status' => $filters['status']['value'] ?? null, 'category' => $filters['category']['value'] ?? null,
            'from' => isset($period['from']) ? substr((string) $period['from'], 0, 10) : null, 'to' => isset($period['to']) ? substr((string) $period['to'], 0, 10) : null],
            fn ($value) => $value !== null && $value !== '');
    }

    /** @return list<array> the school's categories, archived ones too or not */
    private function categories(bool $archived = false): array
    {
        $this->categoryRows ??= SchoolScreen::call(static::$permissions, ExpenseCategoryController::class, 'index', ['include_archived' => true])['data'];
        return array_values(array_filter($this->categoryRows, fn (array $row) => $archived || !$row['archived_at']));
    }

    /** @return list<array> the documents of an expense */
    private function documents(string $expense): array
    {
        return SchoolScreen::call(static::$permissions, ExpenseController::class, 'show', parameters: ['expense' => $expense])['data']['documents'] ?? [];
    }

    private function fields(): array
    {
        return [DatePicker::make('expense_date')->label(__('expenses.date'))->required()->maxDate(now()),
            $this->amountField('amount'),
            // FR-DEP-01 AC1: `salaries` is the payroll's, never offered.
            Select::make('category_id')->label(__('expenses.category'))->required()->options(fn () => collect($this->categories())->reject(fn (array $row) => $row['system'])
                ->mapWithKeys(fn (array $row) => [$row['id'] => Locales::label($row['labels'])])->all()),
            TextInput::make('label')->label(__('expenses.label'))->maxLength(200)->helperText(__('expenses.label_help')),
            TextInput::make('beneficiary')->label(__('expenses.beneficiary'))->required()->minLength(2)->maxLength(200),
            Textarea::make('reason')->label(__('expenses.reason'))->required()->minLength(3)->maxLength(500),
            Select::make('program_id')->label(__('expenses.program'))->helperText(__('expenses.program_help'))
                ->options(fn () => collect(DB::select('SELECT id, code FROM school.program ORDER BY code'))->pluck('code', 'id')->all())];
    }

    private function sent(array $data): array
    {
        return ['expense_date' => substr((string) $data['expense_date'], 0, 10), 'amount' => $this->sentAmount((string) $data['amount']), 'category_id' => $data['category_id'],
            'label' => $data['label'] ?: null, 'beneficiary' => $data['beneficiary'], 'reason' => $data['reason'], 'program_id' => $data['program_id'] ?: null];
    }

    private function own(array $record, string $status): bool
    {
        return $record['status'] === $status && (int) $record['created_by'] === (int) auth()->id() && $this->writable('expense.expense.create');
    }

    private function decider(array $record): string
    {
        return $record['needs_approval'] ? 'expense.expense.approve' : 'expense.expense.validate';
    }

    private function decidable(array $record): bool
    {
        return $record['status'] === 'submitted' && (int) $record['created_by'] !== (int) auth()->id() && $this->writable($this->decider($record));
    }
}
