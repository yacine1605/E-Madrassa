<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\TimeSlotController;
use App\Support\Formats;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * FR-PLN-01: the grid of time slots the sessions are planned on, the whole school's. A slot keeps
 * its code and times; archived, it is no longer offered and the sessions planned on it keep their
 * times. Kept by `schedule.calendar.manage` for the whole school.
 */
final class TimeSlots extends ReferencePage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 21;
    protected static ?string $section = 'planning';
    protected static array $permissions = ['schedule.session.read', 'schedule.calendar.manage'];
    protected static ?string $module = 'scheduling_manual';
    protected static string|array $routeMiddleware = ['module:scheduling_manual', 'permission:schedule.session.read,schedule.calendar.manage'];

    public static function getNavigationLabel(): string { return __('planning.time_slots'); }
    public function getTitle(): string { return __('planning.time_slots'); }

    public function table(Table $table): Table
    {
        $time = fn (string $name, string $label) => TextInput::make($name)->label(__($label))->required()->regex('/^([01]\d|2[0-3]):[0-5]\d$|^24:00$/')
            ->helperText(__('planning.time_help'))->extraInputAttributes(['dir' => 'ltr']);
        return $table->records(function (?string $search, array $filters, int $page, int $recordsPerPage) {
            $rows = SchoolScreen::call(static::$permissions, TimeSlotController::class, 'index', ['include_archived' => true])['data'];
            return $this->catalogPage($this->filterArchived($rows, $filters), $search, $page, $recordsPerPage);
        })->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->description(__('planning.time_slots_help'))
            ->emptyStateHeading(__('planning.no_slot'))->emptyStateDescription(__('planning.no_slot_help'))->columns([
                TextColumn::make('code')->label(__('school.code'))->searchable(),
                TextColumn::make('start_time')->label(__('planning.start')),
                TextColumn::make('end_time')->label(__('planning.end')),
                TextColumn::make('minutes')->label(__('planning.duration'))->formatStateUsing(fn ($state) => __('planning.minutes', ['n' => Formats::number((int) $state)])),
                TextColumn::make('archived_at')->label(__('school.status'))->state(fn (array $record) => __('reference.archive_states.'.($record['archived_at'] ? 'archived' : 'active'))),
            ])->filters([$this->archiveFilter()])->headerActions([
                Action::make('calendar')->label(__('planning.calendar'))->color('gray')->url(fn () => SchoolCalendar::getUrl()),
                Action::make('create')->label(__('planning.add_slot'))->visible(fn () => $this->writable('schedule.calendar.manage'))
                    ->schema([TextInput::make('code')->label(__('school.code'))->required()->regex('/^[A-Za-z0-9_-]{1,20}$/')->helperText(__('planning.code_help')),
                        $time('start_time', 'planning.start'), $time('end_time', 'planning.end')])
                    ->action(fn (array $data) => SchoolScreen::change('schedule.calendar.manage',
                        fn () => SchoolScreen::call('schedule.calendar.manage', TimeSlotController::class, 'store', $data))),
            ])->recordActions([
                Action::make('archive')->label(__('school.archive'))->color('danger')->requiresConfirmation()->modalDescription(__('planning.archive_slot_help'))
                    ->visible(fn (array $record) => !$record['archived_at'] && $this->writable('schedule.calendar.manage'))->schema([$this->reason()])
                    ->action(fn (array $record, array $data) => SchoolScreen::change('schedule.calendar.manage',
                        fn () => SchoolScreen::call('schedule.calendar.manage', TimeSlotController::class, 'archive', $data, ['slot' => $record['id']]))),
            ]);
    }
}
