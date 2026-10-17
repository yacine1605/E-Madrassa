<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\OperationsDashboardController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * FR-TDB-02, « Tableau de bord opérationnel »: for the reader's groups (the whole school, their
 * sites or a teacher's own), each group's fill as its composition list counts it (AC1), and over a
 * period its sessions held, their teacher's attendance validated, those entered by no one, the roll
 * calls not taken and the pupils' absences; for the whole school, the enrolments waiting and the
 * prospects converted. Each figure's basis on its heading; a group's sessions behind its figures
 * (FR-TDB-03). A module's figures appear only while the school uses it (BR-20).
 */
final class OperationsDashboard extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 2;
    protected static ?string $section = 'dashboards';
    protected static array $permissions = ['dashboard.operations.read'];
    protected static ?string $module = 'dashboards';
    protected static string|array $routeMiddleware = ['module:dashboards', 'permission:dashboard.operations.read'];
    private ?array $result = null;
    private ?array $modules = null;

    public static function getNavigationLabel(): string { return __('dashboard.operations'); }
    public function getTitle(): string { return __('dashboard.operations'); }

    public function table(Table $table): Table
    {
        return $table->records(function (array $filters, int $page, int $recordsPerPage) {
            $rows = collect($this->result($filters)['data'])->keyBy('id');
            return new LengthAwarePaginator($rows->forPage($page, $recordsPerPage), $rows->count(), $recordsPerPage, $page);
        })->description(fn () => implode(' ', array_filter([__('dashboard.operations_help'), ...$this->schoolLines(),
                __('dashboard.computed_at', ['at' => Formats::dateTime($this->result($this->tableFilters ?? [])['meta']['computed_at'])])])))
            ->columns([
                TextColumn::make('code')->label(__('dashboard.group'))->placeholder(__('dashboard.none'))
                    ->description(fn (array $record) => implode(' · ', array_filter([$record['labels'] ? Locales::label($record['labels']) : null, $record['program_code']]))),
                TextColumn::make('fill_rate')->label(__('dashboard.fill'))->tooltip(__('dashboard.fill_basis'))->placeholder(__('dashboard.none'))
                    ->state(fn (array $record) => $record['capacity'] === null ? null : __('dashboard.fill_line', ['members' => Formats::number($record['members']),
                        'capacity' => Formats::number($record['capacity']), 'rate' => $this->rate($record['fill_rate'])]))
                    ->visible(fn () => $this->uses('enrollment_groups')),
                TextColumn::make('sessions_held')->label(__('dashboard.sessions_held'))->tooltip(__('dashboard.sessions_basis'))->placeholder(__('dashboard.none'))
                    ->formatStateUsing(fn ($state) => Formats::number((int) $state))->visible(fn () => $this->uses('scheduling_manual')),
                TextColumn::make('teacher_validated_rate')->label(__('dashboard.teacher_validated'))->tooltip(__('dashboard.teacher_basis'))->placeholder(__('dashboard.none'))
                    ->state(fn (array $record) => $record['teacher_validated'] === null ? null : __('dashboard.teacher_line', [
                        'validated' => Formats::number($record['teacher_validated']), 'held' => Formats::number($record['sessions_held']),
                        'rate' => $this->rate($record['teacher_validated_rate'])]))
                    ->visible(fn () => $this->uses('attendance')),
                $this->tally('teacher_missing', 'teacher_missing_basis'),
                $this->tally('roll_missing', 'roll_missing_basis'),
                TextColumn::make('absence_rate')->label(__('dashboard.absences'))->tooltip(__('dashboard.absences_basis'))->placeholder(__('dashboard.none'))
                    ->state(fn (array $record) => $record['marks'] === null ? null : __('dashboard.absences_line', ['absent' => Formats::number($record['absent']),
                        'marks' => Formats::number($record['marks']), 'rate' => $this->rate($record['absence_rate'])]))
                    ->visible(fn () => $this->uses('attendance')),
                TextColumn::make('late')->label(__('dashboard.lates'))->placeholder(__('dashboard.none'))
                    ->formatStateUsing(fn ($state) => Formats::number((int) $state))->visible(fn () => $this->uses('attendance')),
            ])->filters([
                Filter::make('period')->schema([
                    DatePicker::make('from')->label(__('dashboard.period_from'))->default(now()->startOfMonth()->toDateString()),
                    DatePicker::make('to')->label(__('dashboard.period_to'))->default(now()->toDateString())]),
            ])->recordActions([
                Action::make('sessions')->label(__('dashboard.sessions'))->color('gray')->modalSubmitAction(false)->modalDescription(__('dashboard.sessions_help'))
                    ->visible(fn (array $record) => $record['sessions_held'] !== null)
                    ->schema(fn (array $record) => [TextEntry::make('sessions')->hiddenLabel()->listWithLineBreaks()->state($this->sessions($record['group_id']))]),
            ]);
    }

    private function period(array $filters): array
    {
        return ['from' => substr((string) ($filters['period']['from'] ?? now()->startOfMonth()->toDateString()), 0, 10),
            'to' => substr((string) ($filters['period']['to'] ?? now()->toDateString()), 0, 10)];
    }

    private function result(array $filters): array
    {
        return $this->result ??= SchoolScreen::call(static::$permissions, OperationsDashboardController::class, 'index', $this->period($filters));
    }

    private function uses(string $module): bool
    {
        $this->modules ??= collect(DB::select('SELECT key FROM unnest(ARRAY[?, ?, ?]) AS key WHERE school.module_enabled(key)',
            ['enrollment_groups', 'scheduling_manual', 'attendance']))->pluck('key')->all();
        return in_array($module, $this->modules, true);
    }

    private function tally(string $key, string $basis): TextColumn
    {
        return TextColumn::make($key)->label(__('dashboard.'.$key))->tooltip(__('dashboard.'.$basis))->placeholder(__('dashboard.none'))
            ->formatStateUsing(fn ($state) => Formats::number((int) $state))->visible(fn () => $this->uses('attendance'));
    }

    private function rate(?string $rate): string
    {
        return $rate === null ? __('dashboard.none') : Formats::number((float) $rate, 1);
    }

    /** The school's figures, for whoever reads them for the whole school. */
    private function schoolLines(): array
    {
        $school = $this->result($this->tableFilters ?? [])['meta']['school'] ?? null;
        if ($school === null) return [];
        return array_filter([
            isset($school['enrollments_pending']) ? __('dashboard.school_line', ['pending' => Formats::number($school['enrollments_pending'])]) : null,
            isset($school['leads_created']) ? __('dashboard.leads_line', ['created' => Formats::number($school['leads_created']),
                'registered' => Formats::number($school['leads_registered']), 'lost' => Formats::number($school['leads_lost']),
                'rate' => $this->rate($school['conversion_rate'])]) : null,
        ]);
    }

    private function sessions(string $group): array
    {
        $lines = array_map(fn (array $row) => __('dashboard.session_line', ['day' => Formats::date($row['day']), 'time' => $row['start_time'],
                'subject' => $row['subject_code'] ?? __('dashboard.none'), 'teacher' => $row['teacher'] !== '' ? Formats::isolated($row['teacher']) : __('dashboard.none'),
                'state' => $row['teacher_status'] === null ? __('dashboard.none') : __('attendance.states.'.$row['teacher_status']),
                'roll' => $row['roll_marks'] === 0 ? __('dashboard.roll_none') : __('dashboard.roll_line', ['marks' => Formats::number($row['roll_marks']),
                    'absent' => Formats::number($row['absent'])])]),
            SchoolScreen::call(static::$permissions, OperationsDashboardController::class, 'sessions', $this->period($this->tableFilters ?? []) + ['group' => $group])['data']);
        return $lines === [] ? [__('dashboard.no_session')] : $lines;
    }
}
