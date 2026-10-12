<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\SiteController;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * FR-SLL-01: the school's sites, named in French or Arabic, archived with a reason, never
 * deleted; one stays in use (ARC-03). Exists only with the `sites_rooms` module (ARC-04, R09).
 */
final class Sites extends ReferencePage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 35;
    protected static ?string $module = 'sites_rooms';
    protected static string|array $routeMiddleware = ['module:sites_rooms', 'permission:referential.node.read'];

    public static function getNavigationLabel(): string { return __('reference.sites'); }
    public function getTitle(): string { return __('reference.sites'); }

    public function table(Table $table): Table
    {
        return $table->records(function (?string $search, array $filters, int $page, int $recordsPerPage) {
            $rows = SchoolScreen::call('referential.node.read', SiteController::class, 'index', ['include_archived' => true])['data'];
            return $this->catalogPage($this->filterArchived($rows, $filters), $search, $page, $recordsPerPage);
        })->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->columns([
            TextColumn::make('code')->label(__('school.code'))->searchable(),
            TextColumn::make('labels')->label(__('school.name'))->state(fn (array $record) => Locales::label($record['labels'])),
            IconColumn::make('is_default')->label(__('reference.default_site'))->boolean(),
            TextColumn::make('archived_at')->label(__('school.status'))
                ->state(fn (array $record) => __('reference.archive_states.'.($record['archived_at'] ? 'archived' : 'active'))),
        ])->filters([$this->archiveFilter()])->headerActions([
            Action::make('create')->label(__('reference.create_site'))->visible(fn () => $this->writable('site.site.manage'))
                ->schema([TextInput::make('code')->label(__('school.code'))->required()->regex('/^[A-Za-z0-9_-]{1,40}$/')->helperText(__('reference.site_code')),
                    ...$this->labelFields()])
                ->action(fn (array $data) => SchoolScreen::change('site.site.manage',
                    fn () => SchoolScreen::call('site.site.manage', SiteController::class, 'store', $data))),
        ])->recordActions([
            Action::make('edit')->label(__('school.edit'))->visible(fn (array $record) => !$record['archived_at'] && $this->writable('site.site.manage'))
                ->fillForm(fn (array $record) => $record)->schema([...$this->labelFields(), Hidden::make('version')->required()])
                ->action(fn (array $record, array $data) => SchoolScreen::change('site.site.manage',
                    fn () => SchoolScreen::call('site.site.manage', SiteController::class, 'update', $data, ['site' => $record['id']]))),
            Action::make('archive')->label(__('school.archive'))->color('danger')->requiresConfirmation()
                ->visible(fn (array $record) => !$record['archived_at'] && $this->writable('site.site.manage'))->schema([$this->reason()])
                ->action(fn (array $record, array $data) => SchoolScreen::change('site.site.manage',
                    fn () => SchoolScreen::call('site.site.manage', SiteController::class, 'archive', $data, ['site' => $record['id']]))),
        ]);
    }
}
