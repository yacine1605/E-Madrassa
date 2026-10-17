<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\RollCallController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;

/**
 * FR-PRE-01, « Appel des élèves »: the sessions begun on a day that the reader calls, a teacher
 * their own; each session's roll, the pupils its group has that day (AC1), marked together; the
 * history of the marks changed, the former values kept (AC2). A roll not called is no absence.
 */
final class RollCall extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 24;
    protected static ?string $section = 'planning';
    protected static array $permissions = ['attendance.student.entry.create'];
    protected static ?string $module = 'attendance';
    protected static string|array $routeMiddleware = ['module:attendance', 'permission:attendance.student.entry.create'];
    private array $rolls = [];

    public static function getNavigationLabel(): string { return __('attendance.roll_call'); }
    public function getTitle(): string { return __('attendance.roll_call'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (array $filters, int $page, int $recordsPerPage) => $this->apiPage(static::$permissions, RollCallController::class, 'index',
            ['day' => substr((string) ($filters['day']['day'] ?? now()->toDateString()), 0, 10)], $page, $recordsPerPage))
            ->paginationPageOptions([50, 100])->defaultPaginationPageOption(50)->description(__('attendance.roll_call_help'))
            ->emptyStateHeading(__('attendance.no_roll'))
            ->columns([
                TextColumn::make('start_time')->label(__('planning.hours'))->state(fn (array $record) => $record['start_time'].'–'.$record['end_time'])
                    ->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('group_code')->label(__('planning.group'))
                    ->description(fn (array $record) => implode(' · ', array_filter([$record['subject_code'], Locales::label($record['subject_labels'] ?? [])]))),
                TextColumn::make('teacher_last_name')->label(__('planning.teacher'))->state(fn (array $record) => $this->name($record, 'teacher_')),
                TextColumn::make('marked')->label(__('attendance.roll'))->badge()
                    ->state(fn (array $record) => $record['marked'] === 0 ? __('attendance.roll_not_called')
                        : __('attendance.roll_marked', ['marked' => Formats::number($record['marked']), 'pupils' => Formats::number($record['pupils'])]))
                    ->color(fn (array $record) => $record['marked'] === 0 ? 'gray' : ($record['marked'] < $record['pupils'] ? 'warning' : 'success'))
                    ->description(fn (array $record) => $record['absent'] > 0 ? __('attendance.absent_count', ['count' => Formats::number($record['absent'])]) : null),
            ])->filters([
                Filter::make('day')->schema([DatePicker::make('day')->label(__('planning.day'))->default(now()->toDateString())]),
            ])->recordActions([
                Action::make('call')->label(__('attendance.call'))->visible(fn () => SchoolScreen::writable(null))
                    ->fillForm(fn (array $record) => collect($this->roll($record['id']))->mapWithKeys(fn (array $pupil) => ['p_'.$pupil['student_id'] => $pupil['status'] ?? 'present'])->all())
                    ->schema(fn (array $record) => array_map(fn (array $pupil) => Select::make('p_'.$pupil['student_id'])->label($this->name($pupil, '').' ('.$pupil['matricule'].')')
                        ->options(__('attendance.roll_statuses'))->required()->selectablePlaceholder(false), $this->roll($record['id'])))
                    ->action(fn (array $record, array $data) => SchoolScreen::change(null, fn () => SchoolScreen::call('attendance.student.entry.create', RollCallController::class,
                        'store', ['entries' => collect($data)->map(fn ($status, $key) => ['student_id' => substr($key, 2), 'status' => $status])->values()->all()],
                        ['session' => $record['id']]))),
                Action::make('history')->label(__('attendance.roll_history'))->color('gray')->modalSubmitAction(false)
                    ->visible(fn (array $record) => $record['marked'] > 0)
                    ->schema(fn (array $record) => [TextEntry::make('history')->hiddenLabel()->listWithLineBreaks()->state($this->history($record['id']))]),
            ]);
    }

    private function roll(string $session): array
    {
        return $this->rolls[$session] ??= SchoolScreen::call(static::$permissions, RollCallController::class, 'show', [], ['session' => $session])['data'];
    }

    /** AC2: each change, the pupil, the former and the new mark, who and when. */
    private function history(string $session): array
    {
        $lines = array_map(fn (array $change) => Formats::dateTime($change['changed_at']).' · '.$this->name($change, '').' : '
            .__('attendance.roll_statuses.'.$change['previous_status']).' → '.__('attendance.roll_statuses.'.$change['status'])
            .($change['changed_by_name'] !== null ? ' · '.Formats::isolated($change['changed_by_name']) : ''),
            SchoolScreen::call(static::$permissions, RollCallController::class, 'history', [], ['session' => $session])['data']);
        return $lines === [] ? [__('attendance.no_roll_change')] : $lines;
    }

    private function name(array $record, string $prefix): string
    {
        $latin = trim(($record[$prefix.'last_name_lat'] ?? $record[$prefix.'last_name'] ?? '').' '.($record[$prefix.'first_name_lat'] ?? $record[$prefix.'first_name'] ?? ''));
        $arabic = trim(($record[$prefix.'last_name_ar'] ?? '').' '.($record[$prefix.'first_name_ar'] ?? ''));
        return Formats::isolated(app()->getLocale() === 'ar' ? ($arabic ?: $latin) : ($latin ?: $arabic));
    }
}
