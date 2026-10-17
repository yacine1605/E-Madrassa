<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\AbsenceAlertController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * FR-PRE-01, « Alertes d'absence »: the pupils who reached the school's threshold of absences
 * without excuse within its window (SQL 073), the latest first, with the group, the absences
 * counted and the window. For whoever corrects the group's rolls for the whole school or on its
 * site, and its lead teacher; a pupil whose record the reader does not read stays unnamed. What the
 * guardians were sent, and when (SQL 074).
 */
final class AbsenceAlerts extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 25;
    protected static ?string $section = 'planning';
    protected static array $permissions = ['attendance.student.entry.update'];
    protected static ?string $module = 'attendance';
    protected static string|array $routeMiddleware = ['module:attendance', 'permission:attendance.student.entry.update'];

    public static function getNavigationLabel(): string { return __('attendance.absence_alerts'); }
    public function getTitle(): string { return __('attendance.absence_alerts'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (int $page, int $recordsPerPage) => $this->apiPage(static::$permissions, AbsenceAlertController::class, 'index',
            [], $page, $recordsPerPage))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->description(fn () => $this->rule())
            ->emptyStateHeading(__('attendance.no_absence_alert'))
            ->columns([
                TextColumn::make('to_day')->label(__('attendance.alert_day'))->formatStateUsing(fn ($state) => Formats::date($state))
                    ->description(fn (array $record) => __('attendance.alert_window', ['from' => Formats::date($record['from_day']), 'to' => Formats::date($record['to_day'])])),
                TextColumn::make('student_id')->label(__('attendance.pupil'))
                    ->state(fn (array $record) => $record['readable'] ? $this->name($record) : __('dashboard.pupil_hidden'))
                    ->description(fn (array $record) => $record['readable'] ? $record['matricule'] : null),
                TextColumn::make('group_code')->label(__('planning.group'))
                    ->state(fn (array $record) => implode(' · ', array_filter([$record['group_code'], Locales::label($record['group_labels'] ?? [])]))),
                TextColumn::make('absences')->label(__('attendance.absences'))->badge()->color('danger')
                    ->state(fn (array $record) => __('attendance.alert_absences', ['absences' => Formats::number($record['absences']),
                        'threshold' => Formats::number($record['threshold'])])),
                TextColumn::make('messages')->label(__('attendance.guardians_told'))->listWithLineBreaks()
                    ->state(fn (array $record) => array_map(fn (array $message) => __('attendance.message_states.'.$message['status'], [
                        'channel' => __('attendance.channels.'.$message['channel']), 'at' => Formats::dateTime($message['sent_at'])]), $record['messages']))
                    ->placeholder(__('attendance.no_message')),
            ]);
    }

    /** The rule as it applies now, or that none does. */
    private function rule(): string
    {
        $rule = SchoolScreen::call(static::$permissions, AbsenceAlertController::class, 'rule')['data'];
        return (int) $rule['threshold'] === 0 ? __('attendance.no_absence_threshold')
            : __('attendance.absence_alerts_help', ['threshold' => Formats::number($rule['threshold']), 'days' => Formats::number($rule['window_days'])]);
    }

    private function name(array $record): string
    {
        $latin = trim(($record['last_name_lat'] ?? '').' '.($record['first_name_lat'] ?? ''));
        $arabic = trim(($record['last_name_ar'] ?? '').' '.($record['first_name_ar'] ?? ''));
        return Formats::isolated(app()->getLocale() === 'ar' ? ($arabic ?: $latin) : ($latin ?: $arabic));
    }
}
