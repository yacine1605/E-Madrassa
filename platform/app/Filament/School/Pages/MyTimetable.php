<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\TimetableController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FR-PLN-04, « Mon emploi du temps »: a teacher's own sessions, four weeks from the day chosen,
 * cancelled ones shown as such, those they replace or were replaced on said so (FR-PLN-06), their
 * month's timetable (PDF) and their agenda file (ICS). Only for a member whose account is a teacher's record; the database says which
 * sessions are theirs.
 */
final class MyTimetable extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static ?string $section = 'personal';
    protected static ?int $navigationSort = 2;
    protected static array $permissions = ['schedule.session.read'];
    protected static ?string $module = 'scheduling_manual';
    protected static string|array $routeMiddleware = ['module:scheduling_manual', 'permission:schedule.session.read'];

    /** Only for a teacher of the school with an account. */
    public static function canAccess(): bool
    {
        return parent::canAccess() && DB::selectOne("SELECT EXISTS (SELECT 1 FROM school.staff s WHERE s.tenant_id = school.tenant() AND s.user_id = school.actor()
            AND s.staff_type IN ('teacher_internal', 'teacher_external')) AS t")->t;
    }

    public static function getNavigationLabel(): string { return __('planning.my_timetable'); }
    public function getTitle(): string { return __('planning.my_timetable'); }

    public function table(Table $table): Table
    {
        return $table->records(function (array $filters, int $page, int $recordsPerPage) {
            [$from, $to] = $this->range($filters);
            $rows = collect(SchoolScreen::call(static::$permissions, TimetableController::class, 'mine', ['from' => $from, 'to' => $to])['data'])->keyBy('id');
            return new LengthAwarePaginator($rows->forPage($page, $recordsPerPage), $rows->count(), $recordsPerPage, $page);
        })->paginationPageOptions([50, 100])->defaultPaginationPageOption(50)->description(__('planning.my_timetable_help'))
            ->emptyStateHeading(__('planning.no_session'))
            ->columns([
                TextColumn::make('day')->label(__('planning.day'))->formatStateUsing(fn ($state) => Formats::date($state))
                    ->description(fn (array $record) => __('admin.days')[Carbon::parse($record['day'])->dayOfWeek]),
                TextColumn::make('start_time')->label(__('planning.hours'))->state(fn (array $record) => $record['start_time'].'–'.$record['end_time'])
                    ->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('subject_code')->label(__('planning.subject'))
                    ->state(fn (array $record) => implode(' · ', array_filter([$record['subject_code'], Locales::label($record['subject_labels'] ?? [])]))),
                TextColumn::make('group_code')->label(__('planning.group')),
                TextColumn::make('room_code')->label(__('planning.room'))->placeholder(__('planning.no_room')),
                TextColumn::make('status')->label(__('school.status'))->badge()->formatStateUsing(fn (string $state) => __('planning.session_states.'.$state))
                    ->color(fn (string $state) => match ($state) { 'cancelled' => 'gray', 'replaced' => 'warning', default => 'info' })
                    ->description(fn (array $record) => match (true) {
                        $record['status'] !== 'replaced' => null,
                        // The substitute's name, when the teacher may read their record.
                        (bool) $record['replaced_mine'] => $record['teacher_name'] !== null ? __('planning.replaced_by', ['name' => Formats::isolated($record['teacher_name'])]) : null,
                        default => $record['replaced_name'] !== null ? __('planning.replaces', ['name' => Formats::isolated($record['replaced_name'])]) : null,
                    }),
            ])->filters([
                Filter::make('from')->schema([DatePicker::make('from')->label(__('planning.from'))->default(now()->toDateString())]),
            ])->headerActions([
                Action::make('month')->label(__('planning.print_month'))->color('gray')
                    ->url(fn () => url('/api/timetable/month.pdf?'.http_build_query(['mine' => 1, 'month' => substr($this->range($this->tableFilters ?? [])[0], 0, 7)])),
                        shouldOpenInNewTab: true),
                Action::make('ics')->label(__('planning.export_ics'))->color('gray')
                    ->url(fn () => url('/api/timetable/calendar.ics?'.http_build_query(['mine' => 1, 'from' => $this->range($this->tableFilters ?? [])[0],
                        'to' => Carbon::parse($this->range($this->tableFilters ?? [])[0])->addDays(90)->toDateString()]))),
            ]);
    }

    /** Four weeks from the day chosen. */
    private function range(array $filters): array
    {
        $from = Carbon::parse($filters['from']['from'] ?? now()->toDateString());
        return [$from->toDateString(), $from->copy()->addDays(27)->toDateString()];
    }
}
