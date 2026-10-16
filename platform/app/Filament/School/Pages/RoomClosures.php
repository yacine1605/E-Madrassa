<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\RoomClosureController;
use App\Http\Controllers\RoomController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * FR-SLL-02 AC2: a room's dated closures (works), opened from its row (`?room=`): no session on
 * their days. Recorded on the room's site by `room.room.manage`, cancelled until their last day.
 */
final class RoomClosures extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $section = 'referential';
    protected static array $permissions = ['referential.node.read'];
    protected static ?string $module = 'sites_rooms';
    protected static string|array $routeMiddleware = ['module:sites_rooms', 'permission:referential.node.read'];
    #[Locked] public string $room = '';
    private ?array $roomRow = null;

    public function mount(): void
    {
        parent::mount();
        $this->room = (string) request()->query('room', '');
        $this->roomRecord();
    }

    /** The room; one of another school, or unknown, is not found. */
    public function roomRecord(): array
    {
        abort_unless(Str::isUuid($this->room), 404);
        return $this->roomRow ??= SchoolScreen::call('referential.node.read', RoomController::class, 'show', parameters: ['room' => $this->room])['data'];
    }

    public function getTitle(): string
    {
        $room = $this->roomRecord();
        return implode(' · ', array_filter([$room['code'], Locales::label($room['labels'])]));
    }

    public function getSubheading(): ?string
    {
        $room = $this->roomRecord();
        return Formats::list(array_filter([__('reference.closures'), $this->siteName($room['site_id']),
            __('schooling.room_places', ['places' => Formats::number($room['capacity'])]),
            __('reference.room_states.'.($room['archived_at'] ? 'archived' : $room['status']))]));
    }

    public function table(Table $table): Table
    {
        return $table->records(fn (array $filters, int $page, int $recordsPerPage) => $this->apiPage('referential.node.read', RoomClosureController::class, 'index',
            array_filter(['include_past' => ($filters['past']['isActive'] ?? false) ?: null, 'include_cancelled' => ($filters['cancelled']['isActive'] ?? false) ?: null]),
            $page, $recordsPerPage, ['room' => $this->room]))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->description(__('reference.closures_help'))
            ->emptyStateHeading(__('reference.no_closure'))->emptyStateDescription(__('reference.no_closure_help'))
            ->columns([
                TextColumn::make('date_from')->label(__('reference.closed_from'))->formatStateUsing(fn ($state) => Formats::date($state)),
                TextColumn::make('date_to')->label(__('reference.closed_until'))->formatStateUsing(fn ($state) => Formats::date($state)),
                TextColumn::make('days')->label(__('reference.days'))->formatStateUsing(fn ($state) => Formats::number((int) $state)),
                TextColumn::make('reason')->label(__('school.reason'))->wrap()
                    ->description(fn (array $record) => __('reference.recorded_by', ['name' => $record['recorded_by_name'] ?? ''])),
                TextColumn::make('status')->label(__('school.status'))->badge()
                    ->formatStateUsing(fn (string $state) => __('reference.closure_states.'.$state))
                    ->color(fn (string $state) => $state === 'planned' ? 'warning' : 'gray')
                    ->description(fn (array $record) => $record['cancelled_at'] ? __('reference.cancelled_on', ['date' => Formats::date($record['cancelled_at'])]) : null),
            ])->filters([
                Filter::make('past')->label(__('reference.include_past_closures'))->toggle(),
                Filter::make('cancelled')->label(__('reference.include_cancelled_closures'))->toggle(),
            ])->headerActions([
                Action::make('rooms')->label(__('reference.rooms'))->color('gray')->url(fn () => Rooms::getUrl()),
                Action::make('add')->label(__('reference.add_closure'))->visible(fn () => $this->changeable())
                    ->schema([
                        DatePicker::make('date_from')->label(__('reference.closed_from'))->required()->minDate(now()->startOfDay())->live(),
                        DatePicker::make('date_to')->label(__('reference.closed_until'))->required()->minDate(fn (Get $get) => $get('date_from') ?: now()->startOfDay()),
                        Textarea::make('reason')->label(__('school.reason'))->required()->minLength(3)->maxLength(500)->helperText(__('reference.closure_reason_help')),
                    ])
                    ->action(fn (array $data) => SchoolScreen::changeAt('room.room.manage', $this->roomRecord()['site_id'],
                        fn () => SchoolScreen::call('room.room.manage', RoomClosureController::class, 'store', ['date_from' => substr((string) $data['date_from'], 0, 10),
                            'date_to' => substr((string) $data['date_to'], 0, 10), 'reason' => $data['reason']], ['room' => $this->room]))),
            ])->recordActions([ActionGroup::make([
                Action::make('cancel')->label(__('reference.cancel_closure'))->color('warning')->requiresConfirmation()->modalDescription(__('reference.cancel_closure_help'))
                    ->visible(fn (array $record) => $record['status'] === 'planned' && $record['date_to'] >= now()->toDateString()
                        && $this->creatable('room.room.manage', $this->roomRecord()['site_id']))
                    ->action(fn (array $record) => SchoolScreen::changeAt('room.room.manage', $this->roomRecord()['site_id'],
                        fn () => SchoolScreen::call('room.room.manage', RoomClosureController::class, 'cancel', ['version' => $record['version']],
                            ['closure' => $record['id']]))),
            ])]);
    }

    /** A room still in use, on a site of the reader's grant. */
    private function changeable(): bool
    {
        return !$this->roomRecord()['archived_at'] && $this->creatable('room.room.manage', $this->roomRecord()['site_id']);
    }
}
