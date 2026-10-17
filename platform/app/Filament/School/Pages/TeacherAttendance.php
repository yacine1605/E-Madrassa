<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\TeacherAttendanceController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FR-PTG-01, FR-PTG-02, « Pointage des enseignants »: the sessions begun on a day, a week or a
 * month, with their entry or « not entered » (never an absence). The supervisor enters and
 * corrects their own entries; another person validates or rejects them, one by one or all those of
 * the period (TST-05). The database holds each rule (SQL 066).
 */
final class TeacherAttendance extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 22;
    protected static ?string $section = 'planning';
    protected static array $permissions = ['attendance.teacher.entry.create', 'attendance.teacher.validation.approve'];
    protected static ?string $module = 'attendance';
    protected static string|array $routeMiddleware = ['module:attendance', 'permission:attendance.teacher.entry.create,attendance.teacher.validation.approve'];
    private ?array $teachers = null;

    public static function getNavigationLabel(): string { return __('attendance.teacher_attendance'); }
    public function getTitle(): string { return __('attendance.teacher_attendance'); }

    public function table(Table $table): Table
    {
        return $table->records(function (array $filters, int $page, int $recordsPerPage) {
            [$from, $to] = $this->range($filters);
            return $this->apiPage(static::$permissions, TeacherAttendanceController::class, 'index', array_filter(['from' => $from, 'to' => $to,
                'state' => $filters['state']['value'] ?? null, 'group_id' => $filters['group']['value'] ?? null]), $page, $recordsPerPage);
        })->paginationPageOptions([50, 100])->defaultPaginationPageOption(50)->description(__('attendance.teacher_attendance_help'))
            ->emptyStateHeading(__('attendance.no_session'))
            ->columns([
                TextColumn::make('day')->label(__('planning.day'))->formatStateUsing(fn ($state) => Formats::date($state))
                    ->description(fn (array $record) => $record['start_time'].'–'.$record['end_time']),
                TextColumn::make('group_code')->label(__('planning.group'))
                    ->description(fn (array $record) => implode(' · ', array_filter([$record['subject_code'], Locales::label($record['subject_labels'] ?? [])]))),
                TextColumn::make('teacher_last_name')->label(__('planning.teacher'))->state(fn (array $record) => $this->name($record, 'teacher_')),
                TextColumn::make('status')->label(__('attendance.presence'))->badge()->placeholder(__('attendance.states.not_entered'))
                    ->formatStateUsing(fn (string $state) => __('attendance.statuses.'.$state))
                    ->color(fn (string $state) => match ($state) { 'present' => 'success', 'late' => 'warning', 'absent' => 'danger', default => 'info' })
                    ->description(fn (array $record) => $this->detail($record)),
                TextColumn::make('validation_status')->label(__('attendance.state'))->badge()->formatStateUsing(fn (string $state) => __('attendance.states.'.$state))
                    ->color(fn (string $state) => match ($state) { 'validated' => 'success', 'rejected' => 'danger', default => 'warning' })
                    ->description(fn (array $record) => $record['reject_reason'] !== null ? Formats::isolated($record['reject_reason'])
                        : ($record['entered_by_name'] !== null ? __('attendance.entered_by', ['name' => Formats::isolated($record['entered_by_name'])]) : null)),
            ])->filters([
                Filter::make('day')->schema([DatePicker::make('day')->label(__('planning.day'))->default(now()->toDateString()),
                    Select::make('span')->label(__('attendance.span'))->options(__('attendance.spans'))->default('day')->selectablePlaceholder(false)]),
                SelectFilter::make('state')->label(__('attendance.state'))->options(__('attendance.states')),
                SelectFilter::make('group')->label(__('planning.group'))->searchable()->options(fn () => collect(DB::select("SELECT id, code FROM school.study_group
                    WHERE tenant_id = ? AND archived_at IS NULL ORDER BY code", [$this->schoolId]))->pluck('code', 'id')->all()),
            ])->headerActions([
                Action::make('validate_period')->label(__('attendance.validate_period'))->requiresConfirmation()->modalDescription(__('attendance.validate_period_help'))
                    ->visible(fn () => $this->creatable('attendance.teacher.validation.approve'))
                    ->action(function () {
                        [$from, $to] = $this->range($this->tableFilters ?? []);
                        return SchoolScreen::change(null, fn () => SchoolScreen::call('attendance.teacher.validation.approve', TeacherAttendanceController::class,
                            'approvePeriod', array_filter(['from' => $from, 'to' => $to, 'group_id' => $this->tableFilters['group']['value'] ?? null])),
                            fn (array $result) => Notification::make()->title(__('school.saved'))->success()->body(implode("\n", [
                                __('attendance.validated_count', ['count' => Formats::number($result['data']['validated']), 'own' => Formats::number($result['data']['own_left'])]),
                                ...array_unique(array_column($result['data']['refused'], 'message'))])));
                    }),
            ])->recordActions([ActionGroup::make([
                Action::make('enter')->label(__('attendance.enter'))
                    ->visible(fn (array $record) => $record['attendance_id'] === null && $this->creatable('attendance.teacher.entry.create', $record['site_id']))
                    ->schema($this->entryFields())
                    ->action(fn (array $record, array $data) => SchoolScreen::changeAt('attendance.teacher.entry.create', $record['site_id'], fn () => SchoolScreen::call(
                        'attendance.teacher.entry.create', TeacherAttendanceController::class, 'store', $this->entryData($data) + ['session_id' => $record['session_id']]))),
                Action::make('correct')->label(__('attendance.correct'))
                    ->visible(fn (array $record) => $record['attendance_id'] !== null && $record['validation_status'] !== 'validated'
                        && (int) $record['entered_by'] === (int) auth()->id() && $this->creatable('attendance.teacher.entry.update', $record['site_id']))
                    ->fillForm(fn (array $record) => $record)->schema($this->entryFields())
                    ->action(fn (array $record, array $data) => SchoolScreen::changeAt('attendance.teacher.entry.update', $record['site_id'], fn () => SchoolScreen::call(
                        'attendance.teacher.entry.update', TeacherAttendanceController::class, 'update', $this->entryData($data) + ['version' => $record['version']],
                        ['attendance' => $record['attendance_id']]))),
                Action::make('validate')->label(__('attendance.validate'))->color('success')->requiresConfirmation()
                    ->visible(fn (array $record) => $this->decidable($record))
                    ->action(fn (array $record) => SchoolScreen::changeAt('attendance.teacher.validation.approve', $record['site_id'], fn () => SchoolScreen::call(
                        'attendance.teacher.validation.approve', TeacherAttendanceController::class, 'approve', ['version' => $record['version']],
                        ['attendance' => $record['attendance_id']]))),
                Action::make('reject')->label(__('attendance.reject'))->color('danger')->modalDescription(__('attendance.reject_help'))
                    ->visible(fn (array $record) => $this->decidable($record))->schema([$this->reason()])
                    ->action(fn (array $record, array $data) => SchoolScreen::changeAt('attendance.teacher.validation.approve', $record['site_id'], fn () => SchoolScreen::call(
                        'attendance.teacher.validation.approve', TeacherAttendanceController::class, 'reject', ['reason' => $data['reason'], 'version' => $record['version']],
                        ['attendance' => $record['attendance_id']]))),
            ])]);
    }

    /** The day chosen, its week as the school starts its weeks, or its month. */
    private function range(array $filters): array
    {
        $day = Carbon::parse($filters['day']['day'] ?? now()->toDateString());
        switch ($filters['day']['span'] ?? 'day') {
            case 'week':
                $first = (int) json_decode(DB::selectOne("SELECT school.setting_value('calendar.first_day_of_week') AS v")->v ?? '0');
                while ($day->dayOfWeek !== $first) $day = $day->subDay();
                return [$day->toDateString(), $day->copy()->addDays(6)->toDateString()];
            case 'month':
                return [$day->copy()->startOfMonth()->toDateString(), $day->copy()->endOfMonth()->toDateString()];
            default:
                return [$day->toDateString(), $day->toDateString()];
        }
    }

    /** A submitted entry, decided by another person than its author (TST-05). */
    private function decidable(array $record): bool
    {
        return $record['validation_status'] === 'submitted' && (int) $record['entered_by'] !== (int) auth()->id()
            && $this->creatable('attendance.teacher.validation.approve', $record['site_id']);
    }

    private function entryFields(): array
    {
        return [
            Select::make('status')->label(__('attendance.presence'))->options(__('attendance.statuses'))->required()->live(),
            TextInput::make('late_minutes')->label(__('attendance.late_minutes'))->integer()->minValue(1)->maxValue(600)
                ->visible(fn (Get $get) => $get('status') === 'late')->required(fn (Get $get) => $get('status') === 'late'),
            Select::make('replacement_teacher_id')->label(__('attendance.replacement'))->options(fn () => $this->teachers())->searchable()
                ->visible(fn (Get $get) => $get('status') === 'replaced')->required(fn (Get $get) => $get('status') === 'replaced'),
            $this->time('actual_start', 'attendance.actual_start')->visible(fn (Get $get) => in_array($get('status'), ['present', 'late', 'replaced'], true)),
            $this->time('actual_end', 'attendance.actual_end')->visible(fn (Get $get) => in_array($get('status'), ['present', 'late', 'replaced'], true)),
            Textarea::make('note')->label(__('attendance.note'))->maxLength(1000),
        ];
    }

    private function time(string $name, string $label): TextInput
    {
        return TextInput::make($name)->label(__($label))->regex('/^([01]\d|2[0-3]):[0-5]\d$/')->helperText(__('attendance.actual_help'))
            ->extraInputAttributes(['dir' => 'ltr']);
    }

    private function entryData(array $data): array
    {
        return ['status' => $data['status'], 'late_minutes' => isset($data['late_minutes']) && $data['late_minutes'] !== '' ? (int) $data['late_minutes'] : null,
            'replacement_teacher_id' => $data['replacement_teacher_id'] ?? null, 'actual_start' => ($data['actual_start'] ?? null) ?: null,
            'actual_end' => ($data['actual_end'] ?? null) ?: null, 'note' => $data['note'] ?? null];
    }

    /** Late by, replaced by, held at other hours. */
    private function detail(array $record): ?string
    {
        $parts = array_filter([
            $record['status'] === 'late' ? __('attendance.late_by', ['minutes' => Formats::number((int) $record['late_minutes'])]) : null,
            $record['status'] === 'replaced' ? __('attendance.replaced_by', ['name' => $this->name($record, 'replacement_')]) : null,
            $record['actual_start'] !== null ? $record['actual_start'].'–'.$record['actual_end'] : null,
        ]);
        return $parts === [] ? null : implode(' · ', $parts);
    }

    /** The teachers still with the school, for a replacement. */
    private function teachers(): array
    {
        return $this->teachers ??= collect(DB::select("SELECT id, staff_number, last_name_lat, first_name_lat, last_name_ar, first_name_ar FROM school.staff
            WHERE tenant_id = ? AND archived_at IS NULL AND status <> 'left' AND staff_type IN ('teacher_internal', 'teacher_external')
            ORDER BY last_name_lat, first_name_lat", [$this->schoolId]))
            ->mapWithKeys(fn ($row) => [$row->id => $this->name(['x_last_name' => $row->last_name_lat, 'x_first_name' => $row->first_name_lat,
                'x_last_name_ar' => $row->last_name_ar, 'x_first_name_ar' => $row->first_name_ar], 'x_').' ('.$row->staff_number.')'])->all();
    }

    private function name(array $record, string $prefix): string
    {
        $latin = trim(($record[$prefix.'last_name'] ?? '').' '.($record[$prefix.'first_name'] ?? ''));
        $arabic = trim(($record[$prefix.'last_name_ar'] ?? '').' '.($record[$prefix.'first_name_ar'] ?? ''));
        return Formats::isolated((app()->getLocale() === 'ar' ? ($arabic ?: $latin) : ($latin ?: $arabic)) ?: '—');
    }
}
