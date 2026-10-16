<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\RoomController;
use App\Http\Controllers\RoomOptionController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * FR-SLL-02: the rooms of the school's sites, their capacity, type, equipment and state; a room
 * is archived, never deleted. Read with the referential; written on the sites of the reader's
 * grant of `room.room.manage`. Its closures on their own screen; the lists of types and equipment
 * on theirs.
 */
final class Rooms extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 36;
    protected static ?string $section = 'referential';
    protected static array $permissions = ['referential.node.read'];
    protected static ?string $module = 'sites_rooms';
    protected static string|array $routeMiddleware = ['module:sites_rooms', 'permission:referential.node.read'];
    private ?array $optionRows = null;

    public static function getNavigationLabel(): string { return __('reference.rooms'); }
    public function getTitle(): string { return __('reference.rooms'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (?string $search, array $filters, int $page, int $recordsPerPage) => $this->apiPage('referential.node.read',
            RoomController::class, 'index', array_filter(['q' => $search, 'site_id' => $filters['site_id']['value'] ?? null,
                'room_type' => $filters['room_type']['value'] ?? null, 'status' => $filters['status']['value'] ?? null,
                'equipment' => $filters['equipment']['value'] ?? null, 'include_archived' => ($filters['archived']['isActive'] ?? false) ?: null]),
            $page, $recordsPerPage))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)
            ->emptyStateHeading(__('reference.no_room'))->emptyStateDescription(__('reference.no_room_help'))
            ->columns([
                TextColumn::make('site_id')->label(__('schooling.site'))->formatStateUsing(fn (string $state) => $this->siteName($state) ?? ''),
                TextColumn::make('code')->label(__('school.code'))->searchable(),
                TextColumn::make('labels')->label(__('school.name'))->state(fn (array $record) => Locales::label($record['labels'])),
                TextColumn::make('room_type')->label(__('reference.room_type'))->formatStateUsing(fn (string $state) => $this->optionName('room_type', $state)),
                TextColumn::make('capacity')->label(__('reference.capacity'))->formatStateUsing(fn ($state) => Formats::number((int) $state)),
                TextColumn::make('equipment')->label(__('reference.equipment'))->wrap()
                    ->state(fn (array $record) => Formats::list(array_map(fn (string $code) => $this->optionName('equipment', $code), $record['equipment']))),
                TextColumn::make('status')->label(__('school.status'))->badge()
                    ->state(fn (array $record) => $record['archived_at'] ? 'archived' : $record['status'])
                    ->formatStateUsing(fn (string $state) => __('reference.room_states.'.$state))
                    ->color(fn (string $state) => match ($state) { 'active' => 'success', 'unavailable' => 'warning', default => 'gray' })
                    ->description(fn (array $record) => $record['next_closure'] ? __('reference.closed_from_until', [
                        'from' => Formats::date($record['next_closure']['date_from']), 'until' => Formats::date($record['next_closure']['date_to'])]) : null),
            ])->filters([
                SelectFilter::make('site_id')->label(__('schooling.site'))
                    ->options(fn () => collect($this->sites())->mapWithKeys(fn ($site) => [$site['id'] => $this->siteName($site['id'])])->all()),
                SelectFilter::make('room_type')->label(__('reference.room_type'))->options(fn () => $this->options('room_type')),
                SelectFilter::make('equipment')->label(__('reference.equipment'))->options(fn () => $this->options('equipment')),
                SelectFilter::make('status')->label(__('school.status'))->options(collect(__('reference.room_states'))->except('archived')->all()),
                Filter::make('archived')->label(__('reference.include_archived_rooms'))->toggle(),
            ])->headerActions([
                Action::make('options')->label(__('reference.room_options'))->color('gray')->url(fn () => RoomOptions::getUrl()),
                Action::make('create')->label(__('reference.create_room'))->visible(fn () => $this->creatable('room.room.manage'))
                    ->schema(fn () => [$this->siteField('room.room.manage')->helperText(null),
                        TextInput::make('code')->label(__('school.code'))->required()->regex('/^[A-Za-z0-9_-]{1,20}$/')->helperText(__('reference.room_code')),
                        ...$this->labelFields(), ...$this->roomFields()])
                    ->action(fn (array $data) => SchoolScreen::changeAt('room.room.manage', $data['site_id'],
                        fn () => SchoolScreen::call('room.room.manage', RoomController::class, 'store', $this->sent($data)))),
            ])->recordActions([ActionGroup::make([
                Action::make('closures')->label(__('reference.closures'))->url(fn (array $record) => RoomClosures::getUrl(['room' => $record['id']])),
                Action::make('edit')->label(__('school.edit'))->visible(fn (array $record) => !$record['archived_at'] && $this->creatable('room.room.manage', $record['site_id']))
                    ->fillForm(fn (array $record) => $record)
                    ->schema(fn (array $record) => [...$this->labelFields(), ...$this->roomFields($record), Hidden::make('version')->required()])
                    ->action(fn (array $record, array $data) => SchoolScreen::changeAt('room.room.manage', $record['site_id'],
                        fn () => SchoolScreen::call('room.room.manage', RoomController::class, 'update', $this->sent($data), ['room' => $record['id']]))),
                Action::make('archive')->label(__('school.archive'))->color('danger')->requiresConfirmation()->modalDescription(__('reference.archive_room_help'))
                    ->visible(fn (array $record) => !$record['archived_at'] && $this->creatable('room.room.manage', $record['site_id']))
                    ->schema([$this->reason()])
                    ->action(fn (array $record, array $data) => SchoolScreen::changeAt('room.room.manage', $record['site_id'],
                        fn () => SchoolScreen::call('room.room.manage', RoomController::class, 'archive', [...$data, 'version' => $record['version']],
                            ['room' => $record['id']]))),
            ])]);
    }

    /** Capacity, type, equipment and state; a type or equipment archived since stays offered to the room that has it. */
    private function roomFields(?array $room = null): array
    {
        return [
            TextInput::make('capacity')->label(__('reference.capacity'))->required()->numeric()->integer()->minValue(1)->maxValue(10000)
                ->helperText(__('reference.capacity_help')),
            Select::make('room_type')->label(__('reference.room_type'))->required()->options(fn () => $this->options('room_type', $room ? [$room['room_type']] : [])),
            Select::make('equipment')->label(__('reference.equipment'))->multiple()->options(fn () => $this->options('equipment', $room['equipment'] ?? [])),
            Select::make('status')->label(__('school.status'))->required()->default('active')->helperText(__('reference.room_status_help'))
                ->options(collect(__('reference.room_states'))->except('archived')->all()),
        ];
    }

    /** The school's lists, archived entries included, by kind and code. */
    private function optionRows(): array
    {
        return $this->optionRows ??= SchoolScreen::call('referential.node.read', RoomOptionController::class, 'index', ['include_archived' => true])['data'];
    }

    /** @return array<string, string> the entries of a list still in use, and those given */
    private function options(string $kind, array $kept = []): array
    {
        return collect($this->optionRows())->where('kind', $kind)->filter(fn (array $option) => !$option['archived_at'] || in_array($option['code'], $kept, true))
            ->mapWithKeys(fn (array $option) => [$option['code'] => Locales::label($option['labels']) ?? $option['code']])->all();
    }

    private function optionName(string $kind, string $code): string
    {
        $option = collect($this->optionRows())->first(fn (array $option) => $option['kind'] === $kind && $option['code'] === $code);
        return $option ? (Locales::label($option['labels']) ?? $code) : $code;
    }

    private function sent(array $data): array
    {
        $data['capacity'] = (int) $data['capacity'];
        $data['equipment'] = array_values($data['equipment'] ?? []);
        return $data;
    }
}
