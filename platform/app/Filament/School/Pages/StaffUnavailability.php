<?php

namespace App\Filament\School\Pages;

use App\Filament\School\Pages\Concerns\DecidesAvailability;
use App\Http\Controllers\UnavailabilityController;
use App\Support\Formats;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * FR-STF-03: the days a teacher may not teach, from one day until another, with the reason; they
 * prevail over the weekly availability. Declared and decided as the availability; one in force is
 * cancelled until its last day. A leave's follows its leave (FR-STF-04).
 */
final class StaffUnavailability extends StaffMemberPage
{
    use DecidesAvailability;

    protected static bool $isDiscovered = true;

    protected function subject(): string { return __('staff.unavailabilities'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (array $filters, int $page, int $recordsPerPage) => $this->apiPage('staff.profile.read', UnavailabilityController::class, 'index',
            array_filter(['status' => $filters['status']['value'] ?? null, 'include_past' => ($filters['past']['isActive'] ?? false) ?: null]),
            $page, $recordsPerPage, ['staff' => $this->staff]))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->description(__('staff.unavailabilities_help'))
            ->emptyStateHeading(__('staff.no_unavailability'))->emptyStateDescription(__('staff.no_unavailability_help'))
            ->columns([
                TextColumn::make('date_from')->label(__('staff.unavailable_from'))->formatStateUsing(fn ($state) => Formats::date($state)),
                TextColumn::make('date_to')->label(__('staff.unavailable_until'))->formatStateUsing(fn ($state) => Formats::date($state)),
                // A leave's says nothing of the leave (FR-STF-04): its type is read with the leave.
                TextColumn::make('reason')->label(__('school.reason'))->wrap()
                    ->state(fn (array $record) => $record['leave_id'] === null ? $record['reason'] : __('staff.leave_unavailability')),
                TextColumn::make('status')->label(__('school.status'))->badge()
                    ->formatStateUsing(fn (string $state) => __('staff.unavailability_states.'.$state))
                    ->color(fn (string $state) => match ($state) { 'approved' => 'success', 'pending' => 'warning', 'rejected' => 'danger', default => 'gray' })
                    ->description(fn (array $record) => $record['status'] === 'cancelled'
                        ? __('staff.cancelled_on', ['date' => Formats::date($record['cancelled_at'])]) : $this->decision($record)),
            ])->filters([
                SelectFilter::make('status')->label(__('school.status'))->options(__('staff.unavailability_states')),
                Filter::make('past')->label(__('staff.include_past'))->toggle(),
            ])->headerActions([
                Action::make('availability')->label(__('staff.availability'))->color('gray')
                    ->url(fn () => StaffAvailability::getUrl(['staff' => $this->staff])),
                Action::make('declare')->label(__('staff.declare_unavailability'))->visible(fn () => $this->changeable() && $this->teaching())
                    ->modalDescription(fn () => $this->inForceAtOnce() ? __('staff.availability_at_once') : __('staff.availability_waits'))
                    ->schema([
                        DatePicker::make('date_from')->label(__('staff.unavailable_from'))->required()->minDate(now()->startOfDay())->live(),
                        DatePicker::make('date_to')->label(__('staff.unavailable_until'))->required()->minDate(fn (Get $get) => $get('date_from') ?: now()->startOfDay()),
                        Textarea::make('reason')->label(__('school.reason'))->required()->minLength(3)->maxLength(500)->helperText(__('staff.unavailability_reason_help')),
                    ])
                    ->action(fn (array $data) => SchoolScreen::changeFor('staff.availability.manage', 'staff', $this->staff,
                        fn () => SchoolScreen::call('staff.availability.manage', UnavailabilityController::class, 'store',
                            ['date_from' => substr((string) $data['date_from'], 0, 10), 'date_to' => substr((string) $data['date_to'], 0, 10), 'reason' => $data['reason']],
                            ['staff' => $this->staff]))),
            ])->recordActions([ActionGroup::make([
                Action::make('approve')->label(__('staff.approve'))->color('success')
                    ->visible(fn (array $record) => $record['status'] === 'pending' && $this->decidable())
                    ->schema([Textarea::make('decision_reason')->label(__('staff.decision_comment'))->minLength(3)->maxLength(500)])
                    ->action(fn (array $record, array $data) => $this->change($record, 'staff.availability.approve', 'approve', $data)),
                Action::make('reject')->label(__('staff.reject'))->color('danger')
                    ->visible(fn (array $record) => $record['status'] === 'pending' && $this->decidable())
                    ->schema([Textarea::make('decision_reason')->label(__('school.reason'))->required()->minLength(3)->maxLength(500)])
                    ->action(fn (array $record, array $data) => $this->change($record, 'staff.availability.approve', 'reject', $data)),
                Action::make('cancel')->label(__('staff.cancel_unavailability'))->color('warning')->requiresConfirmation()
                    ->modalDescription(__('staff.cancel_unavailability_help'))
                    ->visible(fn (array $record) => $record['status'] === 'approved' && $record['leave_id'] === null && $record['date_to'] >= now()->toDateString()
                        && $this->changeable() && $this->inForceAtOnce())
                    ->action(fn (array $record) => $this->change($record, 'staff.availability.manage', 'cancel', [])),
                Action::make('withdraw')->label(__('staff.withdraw'))->color('gray')->requiresConfirmation()->modalDescription(__('staff.withdraw_help'))
                    ->visible(fn (array $record) => $record['status'] === 'pending' && $this->changeable())
                    ->action(fn (array $record) => SchoolScreen::changeFor('staff.availability.manage', 'staff', $this->staff,
                        fn () => SchoolScreen::call('staff.availability.manage', UnavailabilityController::class, 'destroy',
                            ['version' => $record['version']], ['unavailability' => $record['id']]))),
            ])]);
    }

    private function change(array $record, string $permission, string $method, array $data): void
    {
        $sent = ['version' => $record['version']];
        if ($method !== 'cancel') $sent['decision_reason'] = ($data['decision_reason'] ?? null) ?: null;
        SchoolScreen::changeFor($permission, 'staff', $this->staff, fn () => SchoolScreen::call($permission, UnavailabilityController::class, $method,
            $sent, ['unavailability' => $record['id']]));
    }
}
