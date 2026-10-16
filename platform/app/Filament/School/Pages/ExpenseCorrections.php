<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\ExpenseCorrectionController;
use App\Support\Formats;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * FR-DEP-03: the corrections and cancellations of the school's expenses, the latest first. A
 * validated expense never changes: it is corrected by its linked negative entry, with its reason,
 * validated or rejected by someone else than whoever asked for it (BR-08), the original kept.
 */
final class ExpenseCorrections extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 14;
    protected static ?string $section = 'finance';
    protected static array $permissions = ['expense.expense.read', 'expense.expense.create', 'expense.expense.validate', 'expense.expense.approve', 'expense.expense.pay',
        'expense.expense.reverse'];
    protected static ?string $module = 'expenses';
    protected static string|array $routeMiddleware = ['module:expenses',
        'permission:expense.expense.read,expense.expense.create,expense.expense.validate,expense.expense.approve,expense.expense.pay,expense.expense.reverse'];

    public static function getNavigationLabel(): string { return __('expenses.expense_corrections'); }
    public function getTitle(): string { return __('expenses.expense_corrections'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (?string $search, array $filters, int $page, int $recordsPerPage) => $this->apiPage(static::$permissions, ExpenseCorrectionController::class,
            'index', $this->query($search, $filters), $page, $recordsPerPage))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->description(__('expenses.expense_corrections_help'))
            ->emptyStateHeading(__('expenses.no_expense_correction'))
            ->columns([
                TextColumn::make('beneficiary')->label(__('expenses.beneficiary'))->searchable()->formatStateUsing(fn ($state) => Formats::isolated($state))
                    ->description(fn (array $record) => Formats::list([Formats::date($record['expense_date']), $this->money($record['expense_amount'])])),
                TextColumn::make('kind')->label(__('expenses.kind'))->formatStateUsing(fn (string $state) => __('expenses.correction_kinds.'.$state))
                    ->description(fn (array $record) => Formats::isolated((string) $record['reason'])),
                TextColumn::make('amount')->label(__('expenses.amount'))->formatStateUsing(fn ($state) => '− '.$this->money(ltrim((string) $state, '-'))),
                TextColumn::make('corrected_on')->label(__('expenses.corrected_on'))->formatStateUsing(fn ($state) => Formats::date($state)),
                TextColumn::make('author')->label(__('expenses.by'))->formatStateUsing(fn ($state) => Formats::isolated((string) $state))
                    ->description(fn (array $record) => $record['decider'] ? Formats::isolated($record['decider']) : null),
                TextColumn::make('status')->label(__('expenses.status'))->badge()->formatStateUsing(fn (string $state) => __('expenses.correction_statuses.'.$state))
                    ->color(fn (string $state) => match ($state) { 'validated' => 'success', 'rejected' => 'danger', default => 'warning' })
                    ->description(fn (array $record) => $record['decision_reason'] ? Formats::isolated($record['decision_reason']) : null),
            ])->filters([
                SelectFilter::make('status')->label(__('expenses.status'))->options(__('expenses.correction_statuses')),
                SelectFilter::make('kind')->label(__('expenses.kind'))->options(__('expenses.correction_kinds')),
                Filter::make('period')->schema([DatePicker::make('from')->label(__('expenses.from')), DatePicker::make('to')->label(__('expenses.to'))]),
            ])->recordActions([ActionGroup::make([
                // BR-08: by someone else than whoever asked for it.
                Action::make('validate')->label(__('expenses.validate'))->requiresConfirmation()->visible(fn (array $record) => $this->decidable($record))
                    ->action(fn (array $record) => SchoolScreen::change('expense.expense.validate', fn () => SchoolScreen::call('expense.expense.validate',
                        ExpenseCorrectionController::class, 'validateCorrection', ['version' => $record['version']], ['correction' => $record['id']]))),
                Action::make('reject')->label(__('expenses.reject'))->color('danger')->visible(fn (array $record) => $this->decidable($record))
                    ->schema([Textarea::make('reason')->label(__('expenses.reason'))->required()->minLength(3)->maxLength(500)])
                    ->action(fn (array $record, array $data) => SchoolScreen::change('expense.expense.validate', fn () => SchoolScreen::call('expense.expense.validate',
                        ExpenseCorrectionController::class, 'reject', ['version' => $record['version'], 'reason' => $data['reason']], ['correction' => $record['id']]))),
            ])])->headerActions([
                Action::make('expenses')->label(__('expenses.expenses'))->color('gray')->url(fn () => Expenses::getUrl()),
            ]);
    }

    private function query(?string $search, array $filters): array
    {
        $period = $filters['period'] ?? [];
        return array_filter(['search' => $search ?: null, 'status' => $filters['status']['value'] ?? null, 'kind' => $filters['kind']['value'] ?? null,
            'from' => isset($period['from']) ? substr((string) $period['from'], 0, 10) : null, 'to' => isset($period['to']) ? substr((string) $period['to'], 0, 10) : null],
            fn ($value) => $value !== null && $value !== '');
    }

    private function decidable(array $record): bool
    {
        return $record['status'] === 'pending_validation' && (int) $record['created_by'] !== (int) auth()->id() && $this->writable('expense.expense.validate');
    }
}
