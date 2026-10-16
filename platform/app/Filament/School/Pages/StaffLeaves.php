<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\LeaveController;
use App\Support\Formats;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * FR-STF-04: a member's planned absences, a leave, a training, a sickness; recorded by their
 * manager and cancelled until their last day. A teacher's makes them unavailable on its days,
 * without saying why to whoever reads their record only (FR-STF-03).
 */
final class StaffLeaves extends StaffMemberPage
{
    protected static bool $isDiscovered = true;
    protected static array $permissions = ['staff.leave.read'];
    protected static string|array $routeMiddleware = ['module:staff', 'permission:staff.leave.read'];

    protected function subject(): string { return __('staff.leaves'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (array $filters, int $page, int $recordsPerPage) => $this->apiPage('staff.leave.read', LeaveController::class, 'index',
            array_filter(['leave_type' => $filters['leave_type']['value'] ?? null, 'include_past' => ($filters['past']['isActive'] ?? false) ?: null,
                'include_cancelled' => ($filters['cancelled']['isActive'] ?? false) ?: null]), $page, $recordsPerPage, ['staff' => $this->staff]))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->description(__('staff.leaves_help'))
            ->emptyStateHeading(__('staff.no_leave'))->emptyStateDescription(__('staff.no_leave_help'))
            ->columns([
                TextColumn::make('leave_type')->label(__('staff.leave_type'))->formatStateUsing(fn (string $state) => __('staff.leave_types.'.$state))
                    ->description(fn (array $record) => __('staff.recorded_by', ['name' => $record['recorded_by_name'] ?? ''])),
                TextColumn::make('date_from')->label(__('staff.leave_from'))->formatStateUsing(fn ($state) => Formats::date($state)),
                TextColumn::make('date_to')->label(__('staff.leave_until'))->formatStateUsing(fn ($state) => Formats::date($state)),
                TextColumn::make('days')->label(__('staff.leave_days'))->formatStateUsing(fn ($state) => Formats::number((int) $state)),
                TextColumn::make('state')->label(__('school.status'))->badge()
                    ->formatStateUsing(fn (string $state) => __('staff.leave_states.'.$state))
                    ->color(fn (string $state) => match ($state) { 'under_way' => 'warning', 'upcoming' => 'info', default => 'gray' })
                    ->description(fn (array $record) => $record['cancelled_at'] ? __('staff.cancelled_on', ['date' => Formats::date($record['cancelled_at'])]) : null),
                TextColumn::make('observation')->label(__('staff.observation'))->wrap(),
            ])->filters([
                SelectFilter::make('leave_type')->label(__('staff.leave_type'))->options(__('staff.leave_types')),
                Filter::make('past')->label(__('staff.include_past_leaves'))->toggle(),
                Filter::make('cancelled')->label(__('staff.include_cancelled'))->toggle(),
            ])->headerActions([
                Action::make('contracts')->label(__('staff.contracts'))->color('gray')->visible(fn () => $this->reads('staff.contract.read'))
                    ->url(fn () => StaffContracts::getUrl(['staff' => $this->staff])),
                Action::make('add')->label(__('staff.add_leave'))->visible(fn () => $this->changeable() && $this->member()['status'] !== 'left')
                    ->modalDescription(fn () => $this->teaches() ? __('staff.leave_teacher_help') : null)
                    ->schema([
                        Select::make('leave_type')->label(__('staff.leave_type'))->required()->options(__('staff.leave_types')),
                        DatePicker::make('date_from')->label(__('staff.leave_from'))->required()->live(),
                        DatePicker::make('date_to')->label(__('staff.leave_until'))->required()->minDate(fn (Get $get) => $get('date_from')),
                        Textarea::make('observation')->label(__('staff.observation'))->maxLength(1000)->helperText(__('staff.leave_observation_help')),
                    ])
                    ->action(fn (array $data) => $this->send('store', ['leave_type' => $data['leave_type'], 'date_from' => substr((string) $data['date_from'], 0, 10),
                        'date_to' => substr((string) $data['date_to'], 0, 10), 'observation' => ($data['observation'] ?? null) ?: null], ['staff' => $this->staff])),
            ])->recordActions([ActionGroup::make([
                Action::make('cancel')->label(__('staff.cancel_leave'))->color('warning')->requiresConfirmation()->modalDescription(__('staff.cancel_leave_help'))
                    ->visible(fn (array $record) => $record['status'] === 'planned' && $record['date_to'] >= now()->toDateString() && $this->changeable())
                    ->action(fn (array $record) => $this->send('cancel', ['version' => $record['version']], ['leave' => $record['id']])),
            ])]);
    }

    /** Whoever manages this member's leave, while the record is in use. */
    private function changeable(): bool { return $this->changeableWith('staff.leave.manage'); }

    /** A teacher's leave makes them unavailable on its days. */
    private function teaches(): bool { return in_array($this->member()['staff_type'], ['teacher_internal', 'teacher_external'], true); }

    private function send(string $method, array $data, array $parameters): void
    {
        SchoolScreen::changeFor('staff.leave.manage', 'staff', $this->staff,
            fn () => SchoolScreen::call('staff.leave.manage', LeaveController::class, $method, $data, $parameters));
    }
}
