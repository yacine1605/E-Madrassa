<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\FinancePeriodController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FR-FIN-07, BR-15: the closed months of the school, each with its reconciliation. The finance
 * closes a past month once nothing dated in it waits for its validation, having read its
 * reconciliation; nothing is dated in it afterwards (AC1). The direction reopens it with a
 * reason, never whoever closed it (AC2).
 */
final class FinancePeriods extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 11;
    protected static ?string $section = 'finance';
    protected static array $permissions = ['finance.payment.read', 'finance.period.close', 'finance.period.reopen'];
    protected static ?string $module = 'student_finance';
    protected static string|array $routeMiddleware = ['module:student_finance', 'permission:finance.payment.read,finance.period.close,finance.period.reopen'];
    private ?array $categoryNames = null;

    public static function getNavigationLabel(): string { return __('finance.periods'); }
    public function getTitle(): string { return __('finance.periods'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (array $filters, int $page, int $recordsPerPage) => $this->apiPage(static::$permissions, FinancePeriodController::class, 'index',
            array_filter(['status' => $filters['status']['value'] ?? null]), $page, $recordsPerPage))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->description(__('finance.periods_help'))
            ->emptyStateHeading(__('finance.no_period'))
            ->columns([
                TextColumn::make('period_start')->label(__('finance.month'))->formatStateUsing(fn (string $state) => $this->month($state))
                    ->description(fn (array $record) => Formats::date($record['period_start']).' – '.Formats::date($record['period_end'])),
                TextColumn::make('net')->label(__('finance.net'))->state(fn (array $record) => $this->money($record['report']['net']))
                    ->description(fn (array $record) => __('finance.net_detail', ['payments' => $this->money($record['report']['payments_total']),
                        'reversals' => $this->money($record['report']['reversals']['amount']), 'refunds' => $this->money($record['report']['refunds_total'])])),
                TextColumn::make('author')->label(__('finance.closed_by'))->formatStateUsing(fn ($state) => Formats::isolated($state))
                    ->description(fn (array $record) => Formats::dateTime($record['created_at'])),
                TextColumn::make('status')->label(__('school.status'))->badge()->formatStateUsing(fn (string $state) => __('finance.period_states.'.$state))
                    ->color(fn (string $state) => $state === 'closed' ? 'success' : 'warning')
                    ->description(fn (array $record) => $record['status'] === 'reopened'
                        ? Formats::list([Formats::isolated($record['reopener']), Formats::dateTime($record['reopened_at']), Formats::isolated($record['reopen_reason'])]) : null),
            ])->filters([
                SelectFilter::make('status')->label(__('school.status'))->options(__('finance.period_states')),
            ])->headerActions([
                // A past month, read before it is closed: what came in and went out, what still waits.
                Action::make('close')->label(__('finance.close_period'))->visible(fn () => $this->writable('finance.period.close') && $this->closable() !== [])
                    ->modalDescription(__('finance.close_period_help'))
                    ->schema(fn () => [Select::make('month')->label(__('finance.month'))->required()->live()->options($this->closable()),
                        TextEntry::make('reconciliation')->label(__('finance.reconciliation'))->visible(fn (Get $get) => filled($get('month')))
                            ->state(fn (Get $get) => filled($get('month')) ? $this->reconciliationLines(SchoolScreen::call(['finance.payment.read', 'finance.period.close'],
                                FinancePeriodController::class, 'reconciliation', ['month' => $get('month')])['data']) : null)->listWithLineBreaks()])
                    ->action(fn (array $data) => SchoolScreen::change('finance.period.close', fn () => SchoolScreen::call('finance.period.close',
                        FinancePeriodController::class, 'store', ['month' => $data['month']]))),
            ])->recordActions([ActionGroup::make([
                Action::make('report')->label(__('finance.reconciliation'))->modalHeading(fn (array $record) => __('finance.reconciliation_of', ['month' => $this->month($record['period_start'])]))
                    ->modalSubmitAction(false)
                    ->schema(fn (array $record) => [TextEntry::make('lines')->hiddenLabel()->state($this->reconciliationLines($record['report']))->listWithLineBreaks()]),
                // AC2: by the direction, with its reason, never by whoever closed it (BR-08).
                Action::make('reopen')->label(__('finance.reopen_period'))->color('danger')->modalDescription(__('finance.reopen_period_help'))
                    ->visible(fn (array $record) => $record['status'] === 'closed' && $this->writable('finance.period.reopen') && (int) $record['created_by'] !== (int) auth()->id())
                    ->fillForm(fn (array $record) => ['version' => $record['version']])
                    ->schema([Textarea::make('reason')->label(__('schooling.history_reason'))->required()->minLength(3)->maxLength(500), Hidden::make('version')->required()])
                    ->action(fn (array $record, array $data) => SchoolScreen::change('finance.period.reopen', fn () => SchoolScreen::call('finance.period.reopen',
                        FinancePeriodController::class, 'reopen', ['version' => (int) $data['version'], 'reason' => $data['reason']], ['period' => $record['id']]))),
            ])]);
    }

    /** @return array<string, string> the two past years' ended months not closed now, the latest first */
    private function closable(): array
    {
        $closed = array_column(SchoolScreen::call(static::$permissions, FinancePeriodController::class, 'index',
            ['status' => 'closed', 'paginate' => true, 'per_page' => 100])['data'], 'period_start');
        $months = [];
        $month = Carbon::now('Africa/Algiers')->startOfMonth();
        for ($i = 0; $i < 24; $i++) {
            $month = $month->subMonthNoOverflow();
            if (!in_array($month->toDateString(), $closed, true)) $months[$month->format('Y-m')] = $this->month($month->toDateString());
        }
        return $months;
    }

    /** An expense category's name, its code for whoever reads no expense. */
    private function category(string $code): string
    {
        $this->categoryNames ??= collect(DB::select('SELECT code, labels FROM school.expense_category'))->mapWithKeys(fn ($row) => [$row->code => Locales::label($row->labels)])->all();
        return $this->categoryNames[$code] ?? $code;
    }

    /** @return list<string> a reconciliation read line by line: payments by means, reversals, refunds, net; and before closing, what waits */
    private function reconciliationLines(array $report): array
    {
        $line = fn (string $label, array $row) => __('finance.reconciliation_line', ['label' => $label, 'count' => Formats::number((int) $row['count']),
            'amount' => $this->money($row['amount'])]);
        $means = fn (string $code) => __('finance.methods.'.$code);
        return array_values(array_filter([
            ...array_map(fn (string $code, array $row) => $line(__('finance.reconciliation_payments', ['method' => $means($code)]), $row), array_keys($report['payments']), $report['payments']),
            __('finance.reconciliation_total', ['label' => __('finance.payments_total'), 'amount' => $this->money($report['payments_total'])]),
            $line(__('finance.reconciliation_reversals'), $report['reversals']),
            ...array_map(fn (string $code, array $row) => $line(__('finance.reconciliation_refunds', ['method' => $means($code)]), $row), array_keys($report['refunds']), $report['refunds']),
            __('finance.reconciliation_total', ['label' => __('finance.refunds_total'), 'amount' => $this->money($report['refunds_total'])]),
            (int) $report['carry_overs']['count'] > 0 ? $line(__('finance.reconciliation_carry_overs'), $report['carry_overs']) : null,
            __('finance.reconciliation_total', ['label' => __('finance.net'), 'amount' => $this->money($report['net'])]),
            // SQL 056: the expenses validated with a day in the month, what was paid of them, the month's cash balance.
            ...(isset($report['expenses']) ? [
                ...array_map(fn (string $code, array $row) => $line(__('finance.reconciliation_expenses', ['category' => $this->category($code)]), $row),
                    array_keys($report['expenses']), $report['expenses']),
                __('finance.reconciliation_total', ['label' => __('finance.expenses_total'), 'amount' => $this->money($report['expenses_total'])]),
                // SQL 057: the corrections validated with a day in the month, what is committed once they are counted.
                ...(($report['expense_corrections']['count'] ?? 0) > 0 ? [$line(__('finance.reconciliation_expense_corrections'), $report['expense_corrections']),
                    __('finance.reconciliation_total', ['label' => __('finance.expenses_committed'), 'amount' => $this->money($report['expenses_committed'])])] : []),
                ...array_map(fn (string $code, array $row) => $line(__('finance.reconciliation_expense_payments', ['method' => $means($code)]), $row),
                    array_keys($report['expense_payments']), $report['expense_payments']),
                (int) $report['expense_reversals']['count'] > 0 ? $line(__('finance.reconciliation_expense_reversals'), $report['expense_reversals']) : null,
                __('finance.reconciliation_total', ['label' => __('finance.expenses_paid'), 'amount' => $this->money($report['expenses_paid'])]),
                // SQL 060: the pays' payouts and their reversals; what is paid of the pays equals the `salaries` expenses committed (TST-13).
                ...(isset($report['payroll_payouts']) && ((int) $report['payroll_payouts']['count'] > 0 || (int) $report['payroll_reversals']['count'] > 0) ? [
                    $line(__('finance.reconciliation_payroll_payouts'), $report['payroll_payouts']),
                    (int) $report['payroll_reversals']['count'] > 0 ? $line(__('finance.reconciliation_payroll_reversals'), $report['payroll_reversals']) : null,
                    __('finance.reconciliation_total', ['label' => __('finance.payroll_paid'), 'amount' => $this->money($report['payroll_paid'])]),
                    __('finance.reconciliation_total', ['label' => __('finance.salaries_committed'), 'amount' => $this->money($report['salaries_committed'])]),
                ] : []),
                __('finance.reconciliation_total', ['label' => __('finance.cash_balance'), 'amount' => $this->money($report['cash_balance'])]),
            ] : []),
            ($report['pending'] ?? 0) > 0 ? __('finance.reconciliation_pending', ['count' => Formats::number((int) $report['pending'])]) : null,
        ], fn ($line) => $line !== null));
    }
}
