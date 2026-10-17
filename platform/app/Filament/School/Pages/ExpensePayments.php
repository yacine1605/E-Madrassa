<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\ExpensePaymentController;
use App\Support\Formats;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * FR-DEP-02: the payments of the school's expenses and their reversals, the latest first. A
 * payment never changes (TST-11): it is taken back by its reversal, with its reason, validated
 * or rejected by someone else than whoever asked for it (BR-08), the original kept.
 */
final class ExpensePayments extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 13;
    protected static ?string $section = 'finance';
    protected static array $permissions = ['expense.expense.read', 'expense.expense.create', 'expense.expense.validate', 'expense.expense.approve', 'expense.expense.pay',
        'expense.expense.reverse'];
    protected static ?string $module = 'expenses';
    protected static string|array $routeMiddleware = ['module:expenses',
        'permission:expense.expense.read,expense.expense.create,expense.expense.validate,expense.expense.approve,expense.expense.pay,expense.expense.reverse'];

    public static function getNavigationLabel(): string { return __('expenses.expense_payments'); }
    public function getTitle(): string { return __('expenses.expense_payments'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (?string $search, array $filters, int $page, int $recordsPerPage) => $this->apiPage(static::$permissions, ExpensePaymentController::class, 'index',
            $this->query($search, $filters), $page, $recordsPerPage))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->description(__('expenses.expense_payments_help'))
            ->emptyStateHeading(__('expenses.no_expense_payment'))
            ->columns([
                TextColumn::make('beneficiary')->label(__('expenses.beneficiary'))->searchable()->formatStateUsing(fn ($state) => Formats::isolated($state))
                    ->description(fn (array $record) => Formats::date($record['expense_date'])),
                TextColumn::make('kind')->label(__('expenses.kind'))->formatStateUsing(fn (string $state) => __('expenses.kinds.'.$state))
                    ->description(fn (array $record) => $record['kind'] === 'reversal' ? Formats::isolated((string) $record['reason']) : null),
                TextColumn::make('amount')->label(__('expenses.amount'))
                    ->formatStateUsing(fn ($state, array $record) => ($record['kind'] === 'reversal' ? '− ' : '').$this->money($state)),
                TextColumn::make('paid_on')->label(__('expenses.paid_on'))->formatStateUsing(fn ($state) => Formats::date($state))
                    ->description(fn (array $record) => Formats::list(array_filter([__('finance.methods.'.$record['method_code']),
                        $record['external_ref'] ? Formats::isolated($record['external_ref']) : null]))),
                TextColumn::make('author')->label(__('expenses.by'))->formatStateUsing(fn ($state) => Formats::isolated((string) $state))
                    ->description(fn (array $record) => $record['decider'] ? Formats::isolated($record['decider']) : null),
                TextColumn::make('status')->label(__('expenses.status'))->badge()->formatStateUsing(fn (string $state) => __('expenses.payment_statuses.'.$state))
                    ->color(fn (string $state) => match ($state) { 'validated' => 'success', 'rejected' => 'danger', 'pending_validation' => 'warning', default => 'gray' })
                    ->description(fn (array $record) => $record['decision_reason'] ? Formats::isolated($record['decision_reason']) : null),
            ])->filters([
                SelectFilter::make('status')->label(__('expenses.status'))->options(__('expenses.payment_statuses')),
                SelectFilter::make('kind')->label(__('expenses.kind'))->options(__('expenses.kinds')),
                Filter::make('period')->schema([DatePicker::make('from')->label(__('expenses.from')), DatePicker::make('to')->label(__('expenses.to'))]),
            ])->recordActions([ActionGroup::make([
                // Its reversal, the whole amount, dated today, with its reason; once per key.
                Action::make('reverse')->label(__('expenses.reverse'))->color('danger')->modalDescription(__('expenses.reverse_help'))
                    ->visible(fn (array $record) => $record['kind'] === 'payment' && $record['status'] === 'validated' && $record['payroll_payout_id'] === null
                        && $this->writable('expense.expense.reverse'))
                    ->fillForm(fn () => ['idempotency_key' => (string) Str::uuid()])
                    ->schema([Textarea::make('reason')->label(__('expenses.reason'))->required()->minLength(3)->maxLength(500), Hidden::make('idempotency_key')])
                    ->action(fn (array $record, array $data) => SchoolScreen::change('expense.expense.reverse', fn () => SchoolScreen::call('expense.expense.reverse',
                        ExpensePaymentController::class, 'reverse', ['reason' => $data['reason'], 'idempotency_key' => $data['idempotency_key']], ['payment' => $record['id']]))),
                // BR-08: by someone else than whoever asked for it.
                Action::make('validate')->label(__('expenses.validate'))->requiresConfirmation()->visible(fn (array $record) => $this->decidable($record))
                    ->action(fn (array $record) => SchoolScreen::change('expense.expense.validate', fn () => SchoolScreen::call('expense.expense.validate',
                        ExpensePaymentController::class, 'validateReversal', ['version' => $record['version']], ['payment' => $record['id']]))),
                Action::make('reject')->label(__('expenses.reject'))->color('danger')->visible(fn (array $record) => $this->decidable($record))
                    ->schema([Textarea::make('reason')->label(__('expenses.reason'))->required()->minLength(3)->maxLength(500)])
                    ->action(fn (array $record, array $data) => SchoolScreen::change('expense.expense.validate', fn () => SchoolScreen::call('expense.expense.validate',
                        ExpensePaymentController::class, 'reject', ['version' => $record['version'], 'reason' => $data['reason']], ['payment' => $record['id']]))),
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
