<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\ArrearsController;
use App\Http\Controllers\ExportController;
use App\Support\Formats;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * FR-FIN-06: the open balances and the overdue instalments, by pupil, programme and group, as at a
 * date, with their totals (AC1); exported as the list shows them. Whoever reads no payment never
 * opens it (AC2). It informs: no pupil is suspended or blocked by it.
 */
final class Arrears extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 7;
    protected static ?string $section = 'finance';
    protected static array $permissions = ['finance.payment.read'];
    protected static ?string $module = 'student_finance';
    protected static string|array $routeMiddleware = ['module:student_finance', 'permission:finance.payment.read'];
    private ?array $totalsRow = null;

    public static function getNavigationLabel(): string { return __('finance.arrears'); }
    public function getTitle(): string { return __('finance.arrears'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (?string $search, array $filters, int $page, int $recordsPerPage) => $this->apiPage('finance.payment.read', ArrearsController::class, 'index',
            $this->query($search, $filters), $page, $recordsPerPage))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)
            ->description(fn () => $this->totalsLine())
            ->emptyStateHeading(__('finance.no_arrears'))
            ->recordUrl(fn (array $record) => EnrollmentFinance::getUrl(['enrollment' => $record['enrollment_id']]))
            ->columns([
                TextColumn::make('matricule')->label(__('schooling.matricule'))->searchable(),
                TextColumn::make('pupil')->label(__('schooling.student'))->state(fn (array $record) => $this->personName($record)),
                TextColumn::make('program')->label(__('schooling.program'))->description(fn (array $record) => $record['group_code']),
                TextColumn::make('balance')->label(__('finance.balance'))->formatStateUsing(fn ($state) => $this->money($state))
                    ->description(fn (array $record) => __('finance.of_due', ['due' => $this->money($record['due'])])),
                TextColumn::make('overdue')->label(__('finance.overdue'))->formatStateUsing(fn ($state) => (float) $state > 0 ? $this->money($state) : '')
                    ->color(fn ($state) => (float) $state > 0 ? 'danger' : null)
                    ->description(fn (array $record) => $record['overdue_since'] ? __('finance.overdue_since', ['date' => Formats::date($record['overdue_since'])]) : null),
                TextColumn::make('next_due_date')->label(__('finance.next_due'))->formatStateUsing(fn ($state) => $state ? Formats::date($state) : ''),
            ])->filters([
                Filter::make('as_of')->schema([DatePicker::make('as_of')->label(__('finance.as_of'))]),
                SelectFilter::make('program')->label(__('schooling.program'))->options(fn () => collect(DB::select('SELECT id, code FROM school.program ORDER BY code'))
                    ->pluck('code', 'id')->all()),
                SelectFilter::make('group')->label(__('schooling.group'))->options(fn () => collect(DB::select('SELECT id, code FROM school.study_group ORDER BY code'))
                    ->pluck('code', 'id')->all()),
                Filter::make('overdue_only')->label(__('finance.overdue_only'))->toggle(),
            ])->headerActions([
                // « Export contrôlé »: for whoever reads the payments and exports, logged like any export (FR-IMP-02).
                Action::make('export')->label(__('services.new_export'))->color('gray')->visible(fn () => $this->writable('export.job.create'))
                    ->schema([Radio::make('format')->label(__('services.format'))->required()->default('xlsx')->options(__('services.formats'))])
                    ->action(fn (array $data) => SchoolScreen::change('export.job.create', fn () => SchoolScreen::call('export.job.create', ExportController::class, 'store',
                        ['kind' => 'arrears', 'format' => $data['format'], 'filters' => array_filter($this->query($this->tableSearch, $this->tableFilters ?? []),
                            fn ($value) => $value !== null && $value !== false)]),
                        fn (array $result) => isset($result['data']['job'])
                            ? Notification::make()->title(__('services.export_queued'))->info()
                            : Notification::make()->title(__('services.export_ready', ['rows' => Formats::number($result['data']['rows'])]))->success()->persistent()
                                ->actions([Action::make('download')->label(__('services.download'))->url('/api/files/'.$result['data']['file']['id'].'/content')->openUrlInNewTab()]))),
            ]);
    }

    private function query(?string $search, array $filters): array
    {
        return array_filter(['search' => $search ?: null, 'as_of' => $filters['as_of']['as_of'] ?? null, 'program' => $filters['program']['value'] ?? null,
            'group' => $filters['group']['value'] ?? null, 'overdue_only' => ($filters['overdue_only']['isActive'] ?? false) ?: null], fn ($value) => $value !== null);
    }

    /** AC1: what the listed enrolments owe, have paid and have left, as at the date asked for. */
    private function totalsLine(): string
    {
        $this->totalsRow ??= SchoolScreen::call('finance.payment.read', ArrearsController::class, 'index',
            [...$this->query($this->tableSearch, $this->tableFilters ?? []), 'per_page' => 1, 'page' => 1])['meta'];
        $totals = $this->totalsRow['totals'];
        return __('finance.arrears_totals', ['date' => Formats::date($this->totalsRow['as_of']), 'due' => $this->money($totals['due']),
            'paid' => $this->money($totals['paid']), 'balance' => $this->money($totals['balance']), 'overdue' => $this->money($totals['overdue'])]);
    }
}
