<?php

namespace App\Filament\School\Pages;

use App\Filament\School\Pages\Concerns\DecidesAvailability;
use App\Http\Controllers\AvailabilityController;
use App\Support\Formats;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * FR-STF-03: a teacher's weekly availability, declared by them or their manager from a day on,
 * with the hours a day and a week it bounds; a later declaration replaces it from its first day.
 * When the school asks for it, a change waits for an approver who is not the teacher.
 */
final class StaffAvailability extends StaffMemberPage
{
    use DecidesAvailability;

    protected static bool $isDiscovered = true;

    protected function subject(): string { return __('staff.availability'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (array $filters, int $page, int $recordsPerPage) => $this->apiPage('staff.profile.read', AvailabilityController::class, 'index',
            array_filter(['status' => $filters['status']['value'] ?? null]), $page, $recordsPerPage, ['staff' => $this->staff]))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->description(__('staff.availability_help'))
            ->emptyStateHeading(__('staff.no_availability'))->emptyStateDescription(__('staff.no_availability_help'))
            ->columns([
                TextColumn::make('valid_from')->label(__('staff.available_from'))->formatStateUsing(fn ($state) => Formats::date($state)),
                TextColumn::make('slots')->label(__('staff.slots'))->wrap()->state(fn (array $record) => $this->week($record['slots'])),
                TextColumn::make('weekly_hours')->label(__('staff.weekly_hours'))->formatStateUsing(fn ($state) => $this->hours($state)),
                TextColumn::make('bounds')->label(__('staff.bounds'))->wrap()->state(fn (array $record) => $this->bounds($record)),
                TextColumn::make('state')->label(__('school.status'))->badge()
                    ->formatStateUsing(fn (string $state) => __('staff.availability_states.'.$state))
                    ->color(fn (string $state) => match ($state) { 'in_force' => 'success', 'pending' => 'warning', 'rejected' => 'danger', default => 'gray' })
                    ->description(fn (array $record) => $this->decision($record)),
            ])->filters([
                SelectFilter::make('status')->label(__('school.status'))->options(collect(__('staff.availability_states'))->only(['pending', 'approved', 'rejected'])->all()),
            ])->headerActions([
                Action::make('unavailabilities')->label(__('staff.unavailabilities'))->color('gray')
                    ->url(fn () => StaffUnavailability::getUrl(['staff' => $this->staff])),
                Action::make('declare')->label(__('staff.declare_availability'))->visible(fn () => $this->changeable() && $this->teaching())
                    ->modalDescription(fn () => $this->inForceAtOnce() ? __('staff.availability_at_once') : __('staff.availability_waits'))
                    ->fillForm(fn () => $this->current())
                    ->schema([
                        DatePicker::make('valid_from')->label(__('staff.available_from'))->minDate(now()->startOfDay())->helperText(__('staff.available_from_help')),
                        Repeater::make('slots')->label(__('staff.slots'))->helperText(__('staff.slots_help'))->addActionLabel(__('staff.add_slot'))
                            ->reorderable(false)->maxItems(70)->columns(3)->defaultItems(0)->schema([
                                Select::make('weekday')->label(__('staff.weekday'))->required()->options(fn () => $this->weekdays()),
                                TimePicker::make('start_time')->label(__('staff.start_time'))->required()->seconds(false),
                                TimePicker::make('end_time')->label(__('staff.end_time'))->required()->seconds(false)->after('start_time'),
                            ]),
                        TextInput::make('min_weekly_hours')->label(__('staff.min_weekly_hours'))->numeric()->minValue(0)->maxValue(168)->step(0.25),
                        TextInput::make('max_daily_hours')->label(__('staff.max_daily_hours'))->numeric()->minValue(0.25)->maxValue(24)->step(0.25),
                        TextInput::make('max_weekly_hours')->label(__('staff.max_weekly_hours'))->numeric()->minValue(0.25)->maxValue(168)->step(0.25)
                            ->helperText(__('staff.bounds_help')),
                    ])
                    ->action(fn (array $data) => SchoolScreen::changeFor('staff.availability.manage', 'staff', $this->staff,
                        fn () => SchoolScreen::call('staff.availability.manage', AvailabilityController::class, 'store', $this->sent($data), ['staff' => $this->staff]))),
            ])->recordActions([ActionGroup::make([
                Action::make('approve')->label(__('staff.approve'))->color('success')->modalDescription(__('staff.approve_availability_help'))
                    ->visible(fn (array $record) => $record['status'] === 'pending' && $this->decidable())
                    ->schema([Textarea::make('decision_reason')->label(__('staff.decision_comment'))->minLength(3)->maxLength(500)])
                    ->action(fn (array $record, array $data) => $this->decide($record, 'approve', $data)),
                Action::make('reject')->label(__('staff.reject'))->color('danger')
                    ->visible(fn (array $record) => $record['status'] === 'pending' && $this->decidable())
                    ->schema([Textarea::make('decision_reason')->label(__('school.reason'))->required()->minLength(3)->maxLength(500)])
                    ->action(fn (array $record, array $data) => $this->decide($record, 'reject', $data)),
                Action::make('withdraw')->label(__('staff.withdraw'))->color('gray')->requiresConfirmation()->modalDescription(__('staff.withdraw_help'))
                    ->visible(fn (array $record) => $record['status'] === 'pending' && $this->changeable())
                    ->action(fn (array $record) => SchoolScreen::changeFor('staff.availability.manage', 'staff', $this->staff,
                        fn () => SchoolScreen::call('staff.availability.manage', AvailabilityController::class, 'destroy',
                            ['version' => $record['version']], ['declaration' => $record['id']]))),
            ])]);
    }

    private function decide(array $record, string $decision, array $data): void
    {
        SchoolScreen::changeFor('staff.availability.approve', 'staff', $this->staff, fn () => SchoolScreen::call('staff.availability.approve',
            AvailabilityController::class, $decision, ['version' => $record['version'], 'decision_reason' => ($data['decision_reason'] ?? null) ?: null],
            ['declaration' => $record['id']]));
    }

    /** The week in force, to change from: its slots and its bounds. */
    private function current(): array
    {
        $row = DB::selectOne('SELECT slots, min_weekly_hours, max_daily_hours, max_weekly_hours FROM school.availability_on(?)', [$this->staff]);
        if (!$row) return ['slots' => []];
        return [...(array) $row, 'slots' => json_decode($row->slots, true, 4, JSON_THROW_ON_ERROR)];
    }

    /** As the API takes it: times `HH:MM`, no day for today, blank bounds none. */
    private function sent(array $data): array
    {
        $data['valid_from'] = empty($data['valid_from']) ? null : substr((string) $data['valid_from'], 0, 10);
        if ($data['valid_from'] === null) unset($data['valid_from']);
        $data['slots'] = array_values(array_map(fn (array $slot) => ['weekday' => (int) $slot['weekday'],
            'start_time' => substr((string) $slot['start_time'], 0, 5), 'end_time' => substr((string) $slot['end_time'], 0, 5)], $data['slots'] ?? []));
        foreach (['min_weekly_hours', 'max_daily_hours', 'max_weekly_hours'] as $bound) {
            $data[$bound] = ($data[$bound] ?? '') === '' ? null : $data[$bound];
        }
        return $data;
    }

    /** @return array<int, string> the days of the week from the school's first one (FR-REF-06) */
    private function weekdays(): array
    {
        $first = (int) (DB::selectOne("SELECT school.setting_value('calendar.first_day_of_week') #>> '{}' AS day")->day ?? 0);
        return collect(range(0, 6))->mapWithKeys(fn (int $n) => [($first + $n) % 7 => __('admin.days')[($first + $n) % 7]])->all();
    }

    /** « dimanche 08:00–12:00, 14:00–17:00 · mardi 14:00–17:00 »; none: available at no time. */
    private function week(array $slots): string
    {
        if ($slots === []) return __('staff.no_slot');
        return collect($slots)->groupBy('weekday')->map(fn ($day, $weekday) => __('admin.days')[$weekday].' '
            .$day->map(fn (array $slot) => Formats::isolated($slot['start_time'].'–'.$slot['end_time']))->implode(', '))->implode(' · ');
    }

    private function bounds(array $record): string
    {
        return Formats::list(array_filter([
            $record['min_weekly_hours'] === null ? null : __('staff.bound_min_weekly', ['hours' => $this->hours($record['min_weekly_hours'])]),
            $record['max_daily_hours'] === null ? null : __('staff.bound_max_daily', ['hours' => $this->hours($record['max_daily_hours'])]),
            $record['max_weekly_hours'] === null ? null : __('staff.bound_max_weekly', ['hours' => $this->hours($record['max_weekly_hours'])]),
        ]));
    }
}
