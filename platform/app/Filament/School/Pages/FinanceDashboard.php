<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\FinanceDashboardController;
use App\Http\Controllers\ExportController;
use App\Support\DashboardCache;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-TDB-01, « Tableau de bord financier »: month by month, each figure summed from its source
 * operations when read, with its basis shown; the management result is neither a cash position
 * nor a certified result. A month's operations behind a figure are listed with the reader's rights
 * (FR-TDB-03); a programme's profitability with its method. A module's figures appear only while
 * the school uses it (BR-20).
 */
final class FinanceDashboard extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 1;
    protected static ?string $section = 'dashboards';
    protected static array $permissions = ['dashboard.finance.read'];
    protected static ?string $module = 'dashboards';
    protected static string|array $routeMiddleware = ['module:dashboards', 'permission:dashboard.finance.read'];
    private ?array $result = null;
    private ?array $modules = null;

    public static function getNavigationLabel(): string { return __('dashboard.finance'); }
    public function getTitle(): string { return __('dashboard.finance'); }

    public function table(Table $table): Table
    {
        return $table->records(function (array $filters, int $page, int $recordsPerPage) {
            $rows = collect($this->result($filters)['data'])->keyBy('id');
            return new LengthAwarePaginator($rows->forPage($page, $recordsPerPage), $rows->count(), $recordsPerPage, $page);
        })->paginationPageOptions([13])->defaultPaginationPageOption(13)
            ->description(fn () => implode(' ', array_filter([__('dashboard.finance_help', ['result' => $this->basis()]), $this->arrears(),
                __('dashboard.computed_at', ['at' => Formats::dateTime($this->result($this->tableFilters ?? [])['meta']['computed_at'])])])))
            ->columns([
                TextColumn::make('month')->label(__('dashboard.month'))->formatStateUsing(fn ($state) => Carbon::parse($state)->locale(app()->getLocale())->translatedFormat('F Y')),
                $this->figure('billed', 'student_finance'),
                $this->figure('net_receipts', 'student_finance')->description(fn (array $record) => __('dashboard.net_receipts_detail', [
                    'payments' => $this->amount($record['payments']), 'reversals' => $this->amount($record['reversals']), 'refunds' => $this->amount($record['refunds'])])),
                $this->figure('expenses_committed', 'expenses'),
                $this->figure('expenses_paid', 'expenses'),
                $this->figure('salaries_committed', 'payroll'),
                $this->figure('salaries_paid', 'payroll'),
                $this->figure('net_result', 'student_finance')->tooltip(fn () => Str::ucfirst($this->basis()).'.')->weight('bold'),
            ])->filters([
                Filter::make('period')->schema([
                    DatePicker::make('from')->label(__('dashboard.from'))->default(now()->subMonths(5)->startOfMonth()->toDateString()),
                    DatePicker::make('to')->label(__('dashboard.to'))->default(now()->toDateString())]),
            ])->headerActions([
                Action::make('refresh')->label(__('dashboard.refresh'))->color('gray')->tooltip(__('dashboard.refresh_help'))
                    ->action(function () { DashboardCache::refresh(); $this->result = null; }),
                $this->export(),
                Action::make('programs')->label(__('dashboard.programs'))->color('gray')->modalSubmitAction(false)->modalDescription(fn () => $this->method())
                    ->schema(fn () => [TextEntry::make('programs')->hiddenLabel()->listWithLineBreaks()->state($this->programs())]),
            ])->recordActions([
                Action::make('sources')->label(__('dashboard.sources'))->color('gray')->modalSubmitAction(false)->modalDescription(__('dashboard.sources_help'))
                    ->schema(fn (array $record) => [
                        Select::make('indicator')->label(__('dashboard.indicator'))->live()->selectablePlaceholder(false)->default($this->indicators()[0] ?? null)
                            ->options(collect($this->indicators())->mapWithKeys(fn (string $key) => [$key => __('dashboard.indicators.'.$key)])->all()),
                        TextEntry::make('operations')->hiddenLabel()->listWithLineBreaks()->state(fn (Get $get) => $this->sources($get('indicator'), $record['month'])),
                    ]),
            ]);
    }

    /** FR-TDB-03: the dashboard as it shows, for whoever also exports, logged like any export (FR-IMP-02). */
    private function export(): Action
    {
        return Action::make('export')->label(__('services.new_export'))->color('gray')->visible(fn () => $this->writable('export.job.create'))
            ->schema([Radio::make('format')->label(__('services.format'))->required()->default('xlsx')->options(__('services.formats'))])
            ->action(fn (array $data) => SchoolScreen::change('export.job.create', fn () => SchoolScreen::call('export.job.create', ExportController::class, 'store',
                ['kind' => 'finance_dashboard', 'format' => $data['format'], 'filters' => ['from' => substr((string) ($this->tableFilters['period']['from'] ?? now()->subMonths(5)->startOfMonth()->toDateString()), 0, 10),
                    'to' => substr((string) ($this->tableFilters['period']['to'] ?? now()->toDateString()), 0, 10)]]),
                fn (array $result) => isset($result['data']['job'])
                    ? Notification::make()->title(__('services.export_queued'))->info()
                    : Notification::make()->title(__('services.export_ready', ['rows' => Formats::number($result['data']['rows'])]))->success()->persistent()
                        ->actions([Action::make('download')->label(__('services.download'))->url('/api/files/'.$result['data']['file']['id'].'/content')->openUrlInNewTab()])));
    }

    private function result(array $filters): array
    {
        return $this->result ??= SchoolScreen::call(static::$permissions, FinanceDashboardController::class, 'index', [
            'from' => substr((string) ($filters['period']['from'] ?? now()->subMonths(5)->startOfMonth()->toDateString()), 0, 10),
            'to' => substr((string) ($filters['period']['to'] ?? now()->toDateString()), 0, 10)]);
    }

    private function modules(): array
    {
        return $this->modules ??= collect(DB::select('SELECT key FROM unnest(ARRAY[?, ?, ?]) AS key WHERE school.module_enabled(key)', ['student_finance', 'expenses', 'payroll']))
            ->pluck('key')->all();
    }

    /** The figures whose operations may be listed, of the modules in use. */
    private function indicators(): array
    {
        $by = ['student_finance' => ['billed', 'payments', 'reversals', 'refunds'], 'expenses' => ['expenses_committed', 'expenses_paid'],
            'payroll' => ['salaries_committed', 'salaries_paid']];
        return array_merge(...array_values(array_intersect_key($by, array_flip($this->modules()))), ...[[]]);
    }

    private function figure(string $key, string $module): TextColumn
    {
        return TextColumn::make($key)->label(__('dashboard.indicators.'.$key))->tooltip(__('dashboard.bases.'.$key))
            ->formatStateUsing(fn ($state) => $this->amount($state))->placeholder(__('dashboard.none'))->extraAttributes(['dir' => 'ltr'])
            ->visible(fn () => in_array($module, $this->modules(), true));
    }

    private function amount(?string $value): string
    {
        return $value === null ? __('dashboard.none') : $this->money($value);
    }

    /** The management result's formula, on the school's basis (SQL 071). */
    private function basis(): string
    {
        return __('dashboard.result_bases.'.$this->result($this->tableFilters ?? [])['meta']['basis']);
    }

    /** How the programmes' margins are made: their revenue and direct costs on the result's basis, the common costs kept apart or shared. */
    private function method(): string
    {
        $conventions = FinanceDashboardController::conventions();
        return __('dashboard.programs_methods.'.$conventions['basis']).' '.__('dashboard.sharing.'.$conventions['sharing']);
    }

    private function arrears(): ?string
    {
        $arrears = $this->result($this->tableFilters ?? [])['meta']['arrears'] ?? null;
        return $arrears === null ? null : __('dashboard.arrears_line', ['amount' => $this->money($arrears['amount']),
            'count' => Formats::number((int) $arrears['enrollments']), 'day' => Formats::date(Carbon::parse($arrears['as_of'])->endOfMonth())]);
    }

    /** Each programme's revenue, receipts, direct costs, share of the common costs and margin; the costs no programme carries said missing, or shared. */
    private function programs(): array
    {
        $filters = $this->tableFilters ?? [];
        $result = SchoolScreen::call(static::$permissions, FinanceDashboardController::class, 'programs', [
            'from' => substr((string) ($filters['period']['from'] ?? now()->subMonths(5)->startOfMonth()->toDateString()), 0, 10),
            'to' => substr((string) ($filters['period']['to'] ?? now()->toDateString()), 0, 10)]);
        $lines = array_map(fn (array $row) => implode(' · ', [$row['code'] !== null ? implode(' ', array_filter([$row['code'], Locales::label(json_decode((string) $row['labels'], true) ?? [])]))
                : __('dashboard.no_program'),
            __($row['shared_costs'] === null ? 'dashboard.program_line' : 'dashboard.program_line_shared', ['billed' => $this->money($row['billed']),
                'received' => $this->money($row['received']), 'costs' => $row['direct_costs'] === null ? __('dashboard.costs_missing') : $this->money($row['direct_costs']),
                'shared' => $row['shared_costs'] === null ? null : $this->money($row['shared_costs']),
                'margin' => $row['margin'] === null ? __('dashboard.none') : $this->money($row['margin'])])]), $result['data']);
        if (($common = $result['meta']['common']) !== null) {
            $lines[] = __($common['shared'] ? 'dashboard.common_shared' : 'dashboard.common_unshared', ['total' => $this->money($common['total']),
                'expenses' => $this->money($common['expenses']), 'salaries' => $this->money($common['salaries'])]);
        }
        return $lines === [] ? [__('dashboard.none')] : $lines;
    }

    private function sources(?string $indicator, string $month): array
    {
        if ($indicator === null) return [__('dashboard.none')];
        $lines = array_map(fn (array $row) => implode(' · ', array_filter([Formats::date($row['day']), $this->money($row['amount']), $row['reference'],
                $row['label'] !== null ? Formats::isolated($row['label']) : null])),
            SchoolScreen::call(static::$permissions, FinanceDashboardController::class, 'sources', ['indicator' => $indicator, 'month' => $month])['data']);
        return $lines === [] ? [__('dashboard.no_source')] : $lines;
    }
}
