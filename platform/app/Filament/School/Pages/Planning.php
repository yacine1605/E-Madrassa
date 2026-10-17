<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\ClassSessionController;
use App\Http\Controllers\SessionSeriesController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
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
use Illuminate\Support\Str;

/**
 * FR-PLN-02 to 04: the planning of a week, by group, teacher or room. A weekly course is planned
 * once with its sessions; a session is added, changed on its own (it leaves its series) or
 * cancelled with its reason. The database refuses what conflicts (SQL 062); what is only a warning
 * is told once saved. The week prints as a timetable and exports to an agenda (ICS).
 */
final class Planning extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 19;
    protected static ?string $section = 'planning';
    protected static array $permissions = ['schedule.session.read'];
    protected static ?string $module = 'scheduling_manual';
    protected static string|array $routeMiddleware = ['module:scheduling_manual', 'permission:schedule.session.read'];
    private ?array $options = null;

    public static function getNavigationLabel(): string { return __('planning.planning'); }
    public function getTitle(): string { return __('planning.planning'); }

    public function table(Table $table): Table
    {
        return $table->records(function (array $filters, int $page, int $recordsPerPage) {
            [$from, $to] = $this->week($filters);
            return $this->apiPage(static::$permissions, ClassSessionController::class, 'index', array_filter(['from' => $from, 'to' => $to,
                'group_id' => $filters['group']['value'] ?? null, 'teacher_id' => $filters['teacher']['value'] ?? null, 'room_id' => $filters['room']['value'] ?? null,
                'include_cancelled' => ($filters['cancelled']['isActive'] ?? false) ?: null]), $page, $recordsPerPage);
        })->paginationPageOptions([50, 100])->defaultPaginationPageOption(50)->description(__('planning.planning_help'))
            ->emptyStateHeading(__('planning.no_session'))->emptyStateDescription(__('planning.no_session_help'))
            ->columns([
                TextColumn::make('day')->label(__('planning.day'))->formatStateUsing(fn ($state) => Formats::date($state))
                    ->description(fn (array $record) => __('admin.days')[Carbon::parse($record['day'])->dayOfWeek]),
                TextColumn::make('start_time')->label(__('planning.hours'))->state(fn (array $record) => $record['start_time'].'–'.$record['end_time'])
                    ->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('subject_code')->label(__('planning.subject'))->state(fn (array $record) => $this->label($record['subject_code'], $record['subject_labels'])),
                TextColumn::make('group_code')->label(__('planning.group'))->state(fn (array $record) => $this->label($record['group_code'], $record['group_labels'])),
                TextColumn::make('teacher_last_name')->label(__('planning.teacher'))->state(fn (array $record) => $this->teacherName($record)),
                TextColumn::make('room_code')->label(__('planning.room'))->placeholder(__('planning.no_room')),
                TextColumn::make('status')->label(__('school.status'))->badge()->formatStateUsing(fn (string $state) => __('planning.session_states.'.$state))
                    ->color(fn (string $state) => $state === 'cancelled' ? 'gray' : 'info')
                    ->description(fn (array $record) => $record['cancel_reason'] !== null ? Formats::isolated($record['cancel_reason'])
                        : ($record['detached'] ? __('planning.detached') : ($record['series_id'] ? __('planning.weekly') : null))),
            ])->filters([
                Filter::make('week')->schema([DatePicker::make('week')->label(__('planning.week'))->default(now()->toDateString())]),
                SelectFilter::make('group')->label(__('planning.group'))->options(fn () => $this->options()['groups'])->searchable(),
                SelectFilter::make('teacher')->label(__('planning.teacher'))->options(fn () => $this->options()['teachers'])->searchable(),
                SelectFilter::make('room')->label(__('planning.room'))->options(fn () => $this->options()['rooms'])->searchable(),
                Filter::make('cancelled')->label(__('planning.include_cancelled_sessions'))->toggle(),
            ])->headerActions([
                Action::make('pdf')->label(__('planning.print_week'))->color('gray')->url(fn () => $this->exportUrl('pdf'), shouldOpenInNewTab: true)
                    ->visible(fn () => $this->exportUrl('pdf') !== null),
                Action::make('ics')->label(__('planning.export_ics'))->color('gray')->url(fn () => $this->exportUrl('ics'))
                    ->visible(fn () => $this->exportUrl('ics') !== null),
                Action::make('session')->label(__('planning.add_session'))->visible(fn () => $this->creatable('schedule.session.create'))
                    ->schema([$this->groupField(), $this->subjectField(), ...$this->sessionFields()])
                    ->action(fn (array $data) => SchoolScreen::change(null, fn () => SchoolScreen::call('schedule.session.create', ClassSessionController::class, 'store',
                        $this->sessionData($data) + ['group_id' => $data['group_id'], 'subject_id' => $data['subject_id']]), fn (array $result) => $this->saved($result))),
                Action::make('series')->label(__('planning.plan_series'))->visible(fn () => $this->creatable('schedule.session.create'))
                    ->schema([$this->groupField(), $this->subjectField(), $this->teacherField(), $this->roomField(),
                        Select::make('weekday')->label(__('planning.weekday'))->options(__('admin.days'))->required(),
                        Select::make('slot_id')->label(__('planning.slot'))->options(fn () => $this->options()['slots'])->live()->helperText(__('planning.slot_help')),
                        $this->timeField('start_time', 'planning.start')->required(fn (Get $get) => !$get('slot_id'))->visible(fn (Get $get) => !$get('slot_id')),
                        $this->timeField('end_time', 'planning.end')->required(fn (Get $get) => !$get('slot_id'))->visible(fn (Get $get) => !$get('slot_id')),
                        DatePicker::make('date_from')->label(__('planning.from'))->required()->live(),
                        DatePicker::make('date_to')->label(__('planning.until'))->required()->minDate(fn (Get $get) => $get('date_from'))])
                    ->action(fn (array $data) => SchoolScreen::change(null, fn () => SchoolScreen::call('schedule.session.create', SessionSeriesController::class, 'store',
                        ['group_id' => $data['group_id'], 'subject_id' => $data['subject_id'], 'teacher_id' => $data['teacher_id'], 'room_id' => $data['room_id'] ?? null,
                         'weekday' => (int) $data['weekday'], 'slot_id' => $data['slot_id'] ?? null, 'start_time' => $data['start_time'] ?? null,
                         'end_time' => $data['end_time'] ?? null, 'date_from' => substr((string) $data['date_from'], 0, 10),
                         'date_to' => substr((string) $data['date_to'], 0, 10), 'idempotency_key' => (string) Str::uuid()]), fn (array $result) => $this->saved($result))),
            ])->recordActions([ActionGroup::make([
                Action::make('change')->label(__('planning.change_session'))->modalDescription(fn (array $record) => $record['series_id'] ? __('planning.change_session_help') : null)
                    ->visible(fn (array $record) => $record['status'] === 'planned' && !$record['locked'] && $this->creatable('schedule.session.update', $record['site_id']))
                    ->fillForm(fn (array $record) => $record)->schema([...$this->sessionFields(), Hidden::make('version')->required()])
                    ->action(fn (array $record, array $data) => SchoolScreen::changeAt('schedule.session.update', $record['site_id'], fn () => SchoolScreen::call('schedule.session.update',
                        ClassSessionController::class, 'update', $this->sessionData($data) + ['version' => (int) $data['version']], ['session' => $record['id']]),
                        fn (array $result) => $this->saved($result))),
                Action::make('cancel')->label(__('planning.cancel_session'))->color('danger')->requiresConfirmation()->modalDescription(__('planning.cancel_session_help'))
                    ->visible(fn (array $record) => $record['status'] === 'planned' && !$record['locked'] && $this->creatable('schedule.session.cancel', $record['site_id']))
                    ->schema([Textarea::make('reason')->label(__('school.reason'))->required()->minLength(3)->maxLength(1000)])
                    ->action(fn (array $record, array $data) => SchoolScreen::changeAt('schedule.session.cancel', $record['site_id'], fn () => SchoolScreen::call('schedule.session.cancel',
                        ClassSessionController::class, 'cancel', ['reason' => $data['reason'], 'version' => $record['version']], ['session' => $record['id']]))),
            ])]);
    }

    /** The week the filter's day falls in, from the school's first day of the week. */
    private function week(array $filters): array
    {
        $day = Carbon::parse($filters['week']['week'] ?? now()->toDateString());
        $first = (int) json_decode(DB::selectOne("SELECT school.setting_value('calendar.first_day_of_week') AS v")->v ?? '0');
        while ($day->dayOfWeek !== $first) $day = $day->subDay();
        return [$day->toDateString(), $day->copy()->addDays(6)->toDateString()];
    }

    /** The week's timetable (PDF) or its agenda (ICS), of the group, teacher or room filtered; none before one is chosen. */
    private function exportUrl(string $kind): ?string
    {
        $filters = $this->tableFilters ?? [];
        $scope = array_filter(['group_id' => $filters['group']['value'] ?? null, 'teacher_id' => $filters['teacher']['value'] ?? null,
            'room_id' => $filters['room']['value'] ?? null]);
        if ($scope === []) return null;
        $scope = array_slice($scope, 0, 1, true);
        [$from, $to] = $this->week($filters);
        return $kind === 'pdf' ? url('/api/timetable/week.pdf?'.http_build_query($scope + ['week' => $from]))
            : url('/api/timetable/calendar.ics?'.http_build_query($scope + ['from' => $from, 'to' => Carbon::parse($from)->addDays(90)->toDateString()]));
    }

    /** What the reader may plan with: the active groups, the teachers still with the school, the rooms in use, the subjects, the slots. */
    private function options(): array
    {
        return $this->options ??= [
            'groups' => collect(DB::select("SELECT id, code, labels FROM school.study_group WHERE tenant_id = ? AND archived_at IS NULL AND status = 'active' ORDER BY code",
                [$this->schoolId]))->mapWithKeys(fn ($row) => [$row->id => $this->label($row->code, json_decode($row->labels, true))])->all(),
            'teachers' => collect(DB::select("SELECT id, staff_number, last_name_lat, first_name_lat, last_name_ar, first_name_ar FROM school.staff WHERE tenant_id = ?
                AND archived_at IS NULL AND status <> 'left' AND staff_type IN ('teacher_internal', 'teacher_external') ORDER BY last_name_lat, first_name_lat", [$this->schoolId]))
                ->mapWithKeys(fn ($row) => [$row->id => $this->teacherName(['teacher_last_name' => $row->last_name_lat, 'teacher_first_name' => $row->first_name_lat,
                    'teacher_last_name_ar' => $row->last_name_ar, 'teacher_first_name_ar' => $row->first_name_ar]).' ('.$row->staff_number.')'])->all(),
            'rooms' => collect(DB::select("SELECT id, code, labels, capacity FROM school.room WHERE tenant_id = ? AND archived_at IS NULL AND status = 'active' ORDER BY code",
                [$this->schoolId]))->mapWithKeys(fn ($row) => [$row->id => $this->label($row->code, json_decode($row->labels, true)).' · '
                    .__('schooling.room_places', ['places' => Formats::number((int) $row->capacity)])])->all(),
            'subjects' => collect(DB::select('SELECT id, code, labels FROM school.subject WHERE tenant_id = ? AND archived_at IS NULL ORDER BY code', [$this->schoolId]))
                ->mapWithKeys(fn ($row) => [$row->id => $this->label($row->code, json_decode($row->labels, true))])->all(),
            'slots' => collect(DB::select("SELECT id, code, to_char(start_time, 'HH24:MI') AS s, CASE WHEN end_time = '24:00' THEN '24:00' ELSE to_char(end_time, 'HH24:MI') END AS e
                FROM school.time_slot WHERE tenant_id = ? AND archived_at IS NULL ORDER BY start_time, end_time", [$this->schoolId]))
                ->mapWithKeys(fn ($row) => [$row->id => $row->code.' · '.$row->s.'–'.$row->e])->all(),
        ];
    }

    private function groupField(): Select { return Select::make('group_id')->label(__('planning.group'))->options(fn () => $this->options()['groups'])->searchable()->required(); }
    private function subjectField(): Select { return Select::make('subject_id')->label(__('planning.subject'))->options(fn () => $this->options()['subjects'])->searchable()->required(); }
    private function teacherField(): Select { return Select::make('teacher_id')->label(__('planning.teacher'))->options(fn () => $this->options()['teachers'])->searchable()->required(); }
    private function roomField(): Select { return Select::make('room_id')->label(__('planning.room'))->options(fn () => $this->options()['rooms'])->searchable(); }

    private function timeField(string $name, string $label): TextInput
    {
        return TextInput::make($name)->label(__($label))->regex('/^([01]\d|2[0-3]):[0-5]\d$|^24:00$/')->helperText(__('planning.time_help'))
            ->extraInputAttributes(['dir' => 'ltr']);
    }

    /** A session's teacher, room, day and hours. */
    private function sessionFields(): array
    {
        return [$this->teacherField(), $this->roomField(), DatePicker::make('day')->label(__('planning.day'))->required(),
            $this->timeField('start_time', 'planning.start')->required(), $this->timeField('end_time', 'planning.end')->required()];
    }

    private function sessionData(array $data): array
    {
        return ['teacher_id' => $data['teacher_id'], 'room_id' => $data['room_id'] ?? null, 'day' => substr((string) $data['day'], 0, 10),
            'start_time' => $data['start_time'], 'end_time' => $data['end_time']];
    }

    /** Saved; with what is only a warning (FR-PLN-03), told. */
    private function saved(array $result): Notification
    {
        $warnings = array_unique(array_column($result['warnings'] ?? [], 'message'));
        return $warnings === [] ? Notification::make()->title(__('school.saved'))->success()
            : Notification::make()->title(__('planning.saved_with_warnings'))->body(implode("\n", $warnings))->warning()->persistent();
    }

    private function label(?string $code, ?array $labels): string
    {
        return implode(' · ', array_filter([$code, $labels ? Locales::label($labels) : null]));
    }

    private function teacherName(array $record): string
    {
        $latin = trim(($record['teacher_last_name'] ?? '').' '.($record['teacher_first_name'] ?? ''));
        $arabic = trim(($record['teacher_last_name_ar'] ?? '').' '.($record['teacher_first_name_ar'] ?? ''));
        return Formats::isolated((app()->getLocale() === 'ar' ? ($arabic ?: $latin) : ($latin ?: $arabic)) ?: '—');
    }
}
