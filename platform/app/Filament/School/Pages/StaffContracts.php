<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\ContractController;
use App\Support\Formats;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

/**
 * FR-STF-04: a member's contract in dated versions, its type, dates and contractual hours, never
 * an amount (AC1). A new contract, an amendment from a day (the version in force ends the day
 * before), an earlier end, a version not begun deleted.
 */
final class StaffContracts extends StaffMemberPage
{
    protected static bool $isDiscovered = true;
    protected static array $permissions = ['staff.contract.read'];
    protected static string|array $routeMiddleware = ['module:staff', 'permission:staff.contract.read'];

    protected function subject(): string { return __('staff.contracts'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (int $page, int $recordsPerPage) => $this->apiPage('staff.contract.read', ContractController::class, 'index', [],
            $page, $recordsPerPage, ['staff' => $this->staff]))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->description(__('staff.contracts_help'))
            ->emptyStateHeading(__('staff.no_contract'))->emptyStateDescription(__('staff.no_contract_help'))
            ->columns([
                TextColumn::make('contract_type')->label(__('staff.contract_type'))->formatStateUsing(fn (string $state) => __('staff.contract_types.'.$state))
                    ->description(fn (array $record) => $record['reference']),
                TextColumn::make('valid_from')->label(__('staff.contract_from'))->formatStateUsing(fn ($state) => Formats::date($state)),
                TextColumn::make('valid_to')->label(__('staff.contract_until'))->placeholder(__('staff.contract_open'))
                    ->formatStateUsing(fn ($state) => Formats::date($state)),
                TextColumn::make('weekly_hours')->label(__('staff.contract_weekly_hours'))->formatStateUsing(fn ($state) => $this->hours($state)),
                TextColumn::make('state')->label(__('school.status'))->badge()
                    ->formatStateUsing(fn (string $state) => __('staff.contract_states.'.$state))
                    ->color(fn (string $state) => match ($state) { 'in_force' => 'success', 'upcoming' => 'info', default => 'gray' })
                    ->description(fn (array $record) => $record['end_reason'] ? __('staff.ended_because', ['reason' => $record['end_reason']]) : null),
                TextColumn::make('observation')->label(__('staff.observation'))->wrap()->toggleable(isToggledHiddenByDefault: true),
            ])->headerActions([
                Action::make('leaves')->label(__('staff.leaves'))->color('gray')->visible(fn () => $this->reads('staff.leave.read'))
                    ->url(fn () => StaffLeaves::getUrl(['staff' => $this->staff])),
                Action::make('pay_rules')->label(__('payroll.rules'))->color('gray')->visible(fn () => StaffPayRules::canAccess())
                    ->url(fn () => StaffPayRules::getUrl(['staff' => $this->staff])),
                Action::make('add')->label(__('staff.add_contract'))->visible(fn () => $this->changeable() && $this->member()['status'] !== 'left')
                    ->schema([
                        ...$this->terms(),
                        DatePicker::make('valid_from')->label(__('staff.contract_from'))->required()->default(now()->toDateString())->live(),
                        $this->until()->minDate(fn (Get $get) => $get('valid_from')),
                    ])
                    ->action(fn (array $data) => $this->send('store', $data, ['staff' => $this->staff])),
            ])->recordActions([ActionGroup::make([
                Action::make('amend')->label(__('staff.amend_contract'))->modalDescription(__('staff.amend_contract_help'))
                    ->visible(fn (array $record) => $record['state'] !== 'ended' && $this->changeable() && $this->member()['status'] !== 'left')
                    ->fillForm(fn (array $record) => [...$record, 'valid_from' => null, 'observation' => null])
                    ->schema(fn (array $record) => [
                        DatePicker::make('valid_from')->label(__('staff.amend_from'))->required()->live()
                            ->minDate(Carbon::parse($record['valid_from'])->addDay())->maxDate($record['valid_to']),
                        ...$this->terms(),
                        $this->until()->minDate(fn (Get $get) => $get('valid_from')),
                    ])
                    ->action(fn (array $record, array $data) => $this->send('amend', [...$data, 'version' => $record['version']], ['contract' => $record['id']])),
                Action::make('end')->label(__('staff.end_contract'))->color('warning')->modalDescription(__('staff.end_contract_help'))
                    ->visible(fn (array $record) => $record['state'] !== 'ended' && $this->changeable())
                    ->schema(fn (array $record) => [
                        DatePicker::make('valid_to')->label(__('staff.end_on'))->required()->default(now()->toDateString())
                            ->minDate($record['valid_from'])->maxDate($record['valid_to']),
                        Textarea::make('end_reason')->label(__('staff.end_reason'))->minLength(3)->maxLength(500),
                    ])
                    ->action(fn (array $record, array $data) => $this->send('end', ['valid_to' => $this->day($data['valid_to']),
                        'end_reason' => ($data['end_reason'] ?? null) ?: null, 'version' => $record['version']], ['contract' => $record['id']])),
                Action::make('delete')->label(__('staff.delete_contract'))->color('danger')->requiresConfirmation()
                    ->modalDescription(__('staff.delete_contract_help'))
                    ->visible(fn (array $record) => $record['state'] === 'upcoming' && $this->changeable())
                    ->action(fn (array $record) => $this->send('destroy', ['version' => $record['version']], ['contract' => $record['id']])),
            ])]);
    }

    /** Whoever manages this member's contracts, while the record is in use. */
    private function changeable(): bool { return $this->changeableWith('staff.contract.manage'); }

    /** What a version says, but its dates. */
    private function terms(): array
    {
        return [
            Select::make('contract_type')->label(__('staff.contract_type'))->required()->live()->options(__('staff.contract_types')),
            TextInput::make('reference')->label(__('staff.contract_reference'))->maxLength(60),
            TextInput::make('weekly_hours')->label(__('staff.contract_weekly_hours'))->numeric()->minValue(0.25)->maxValue(168)->step(0.25),
            Textarea::make('observation')->label(__('staff.observation'))->maxLength(1000)->helperText(__('staff.contract_observation_help')),
        ];
    }

    /** Required for a fixed-term contract or an internship. */
    private function until(): DatePicker
    {
        return DatePicker::make('valid_to')->label(__('staff.contract_until'))->helperText(__('staff.contract_until_help'))
            ->required(fn (Get $get) => in_array($get('contract_type'), ['fixed_term', 'internship'], true));
    }

    /** As the API takes it: days without their time, blanks none. */
    private function send(string $method, array $data, array $parameters): void
    {
        foreach (['valid_from', 'valid_to'] as $day) if (array_key_exists($day, $data)) $data[$day] = $this->day($data[$day]);
        foreach (['reference', 'weekly_hours', 'observation'] as $field) if (($data[$field] ?? null) === '') $data[$field] = null;
        SchoolScreen::changeFor('staff.contract.manage', 'staff', $this->staff,
            fn () => SchoolScreen::call('staff.contract.manage', ContractController::class, $method, $data, $parameters));
    }

    private function day(mixed $day): ?string { return empty($day) ? null : substr((string) $day, 0, 10); }
}
