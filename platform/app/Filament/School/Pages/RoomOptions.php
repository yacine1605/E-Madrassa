<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\RoomOptionController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * FR-SLL-02: the school's lists of room types and equipment, given with the publisher's defaults
 * and extended by the school; an entry is archived, never deleted, and the rooms keep it.
 */
final class RoomOptions extends ReferencePage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 37;
    protected static ?string $module = 'sites_rooms';
    protected static string|array $routeMiddleware = ['module:sites_rooms', 'permission:referential.node.read'];

    public static function getNavigationLabel(): string { return __('reference.room_options'); }
    public function getTitle(): string { return __('reference.room_options'); }

    public function table(Table $table): Table
    {
        return $table->records(function (?string $search, array $filters, int $page, int $recordsPerPage) {
            $rows = SchoolScreen::call('referential.node.read', RoomOptionController::class, 'index',
                array_filter(['include_archived' => true, 'kind' => $filters['kind']['value'] ?? null]))['data'];
            return $this->catalogPage($this->filterArchived($rows, $filters), $search, $page, $recordsPerPage);
        })->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->description(__('reference.room_options_help'))->columns([
            TextColumn::make('kind')->label(__('reference.list'))->formatStateUsing(fn (string $state) => __('reference.room_option_kinds.'.$state)),
            TextColumn::make('code')->label(__('school.code'))->searchable(),
            TextColumn::make('labels')->label(__('school.name'))->state(fn (array $record) => Locales::label($record['labels'])),
            TextColumn::make('sort_order')->label(__('reference.sort_order')),
            TextColumn::make('rooms')->label(__('reference.rooms'))->formatStateUsing(fn ($state) => Formats::number((int) $state)),
            TextColumn::make('archived_at')->label(__('school.status'))->state(fn (array $record) => __('reference.archive_states.'.($record['archived_at'] ? 'archived' : 'active'))),
        ])->filters([
            SelectFilter::make('kind')->label(__('reference.list'))->options(__('reference.room_option_kinds')),
            $this->archiveFilter(),
        ])->headerActions([
            Action::make('rooms')->label(__('reference.rooms'))->color('gray')->url(fn () => Rooms::getUrl()),
            // A school not in use when it received the module has none of the publisher's entries.
            Action::make('defaults')->label(__('reference.room_option_defaults'))->color('gray')->requiresConfirmation()
                ->modalDescription(__('reference.room_option_defaults_help'))
                ->visible(fn () => $this->writable('room.room.manage') && app(RoomOptionController::class)->missingDefaults() > 0)
                ->action(fn () => SchoolScreen::change('room.room.manage', fn () => SchoolScreen::call('room.room.manage', RoomOptionController::class, 'defaults'))),
            Action::make('create')->label(__('reference.create_room_option'))->visible(fn () => $this->writable('room.room.manage'))
                ->schema([Select::make('kind')->label(__('reference.list'))->required()->options(__('reference.room_option_kinds')),
                    $this->codeField(), ...$this->labelFields(), $this->orderField()])
                ->action(fn (array $data) => SchoolScreen::change('room.room.manage',
                    fn () => SchoolScreen::call('room.room.manage', RoomOptionController::class, 'store', $data))),
        ])->recordActions([
            Action::make('edit')->label(__('school.edit'))->visible(fn (array $record) => !$record['archived_at'] && $this->writable('room.room.manage'))
                ->fillForm(fn (array $record) => $record)->schema([...$this->labelFields(), $this->orderField(), Hidden::make('version')->required()])
                ->action(fn (array $record, array $data) => SchoolScreen::change('room.room.manage',
                    fn () => SchoolScreen::call('room.room.manage', RoomOptionController::class, 'update', $data, ['option' => $record['id']]))),
            Action::make('archive')->label(__('school.archive'))->color('danger')->requiresConfirmation()->modalDescription(__('reference.archive_room_option_help'))
                ->visible(fn (array $record) => !$record['archived_at'] && $this->writable('room.room.manage'))->schema([$this->reason()])
                ->action(fn (array $record, array $data) => SchoolScreen::change('room.room.manage',
                    fn () => SchoolScreen::call('room.room.manage', RoomOptionController::class, 'archive', $data, ['option' => $record['id']]))),
        ]);
    }
}
