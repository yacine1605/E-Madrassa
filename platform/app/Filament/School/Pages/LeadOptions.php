<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\LeadOptionController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * FR-CRM-01: the school's lists of the prospects' sources and steps, given with the publisher's
 * defaults and extended by the school; opened from the prospects. An entry is archived, never
 * deleted, and the prospects keep it; the pipeline's own steps stay.
 */
final class LeadOptions extends ReferencePage
{
    protected static bool $isDiscovered = true;
    protected static bool $shouldRegisterNavigation = false;
    protected static array $permissions = ['crm.lead.read'];
    protected static ?string $section = 'schooling';
    protected static ?string $module = 'students_crm';
    protected static string|array $routeMiddleware = ['module:students_crm', 'permission:crm.lead.read'];

    public static function getNavigationLabel(): string { return __('admission.options'); }
    public function getTitle(): string { return __('admission.options'); }

    public function table(Table $table): Table
    {
        return $table->records(function (?string $search, array $filters, int $page, int $recordsPerPage) {
            $rows = SchoolScreen::call('crm.lead.read', LeadOptionController::class, 'index',
                array_filter(['include_archived' => true, 'kind' => $filters['kind']['value'] ?? null]))['data'];
            return $this->catalogPage($this->filterArchived($rows, $filters), $search, $page, $recordsPerPage);
        })->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->description(__('admission.options_help'))->columns([
            TextColumn::make('kind')->label(__('reference.list'))->formatStateUsing(fn (string $state) => __('admission.option_kinds.'.$state)),
            TextColumn::make('code')->label(__('school.code'))->searchable()
                ->description(fn (array $record) => $record['reserved'] ? __('admission.reserved') : null),
            TextColumn::make('labels')->label(__('school.name'))->state(fn (array $record) => Locales::label($record['labels'])),
            TextColumn::make('sort_order')->label(__('reference.sort_order')),
            TextColumn::make('leads')->label(__('admission.leads'))->formatStateUsing(fn ($state) => Formats::number((int) $state)),
            TextColumn::make('archived_at')->label(__('school.status'))->state(fn (array $record) => __('reference.archive_states.'.($record['archived_at'] ? 'archived' : 'active'))),
        ])->filters([
            SelectFilter::make('kind')->label(__('reference.list'))->options(__('admission.option_kinds')),
            $this->archiveFilter(),
        ])->headerActions([
            Action::make('leads')->label(__('admission.leads'))->color('gray')->url(fn () => Leads::getUrl()),
            // A school not in use when it received the module has none of the publisher's entries.
            Action::make('defaults')->label(__('admission.defaults'))->color('gray')->requiresConfirmation()
                ->modalDescription(__('admission.defaults_help'))
                ->visible(fn () => $this->writable('crm.lead.update') && app(LeadOptionController::class)->missingDefaults() > 0)
                ->action(fn () => SchoolScreen::change('crm.lead.update', fn () => SchoolScreen::call('crm.lead.update', LeadOptionController::class, 'defaults'))),
            Action::make('create')->label(__('admission.create_option'))->visible(fn () => $this->writable('crm.lead.update'))
                ->schema([Select::make('kind')->label(__('reference.list'))->required()->options(__('admission.option_kinds')),
                    TextInput::make('code')->label(__('school.code'))->required()->regex('/^[a-z0-9_]{1,30}$/')->helperText(__('admission.option_code')),
                    ...$this->labelFields(), $this->orderField()])
                ->action(fn (array $data) => SchoolScreen::change('crm.lead.update',
                    fn () => SchoolScreen::call('crm.lead.update', LeadOptionController::class, 'store', $data))),
        ])->recordActions([
            Action::make('edit')->label(__('school.edit'))->visible(fn (array $record) => !$record['archived_at'] && $this->writable('crm.lead.update'))
                ->fillForm(fn (array $record) => $record)->schema([...$this->labelFields(), $this->orderField(), Hidden::make('version')->required()])
                ->action(fn (array $record, array $data) => SchoolScreen::change('crm.lead.update',
                    fn () => SchoolScreen::call('crm.lead.update', LeadOptionController::class, 'update', $data, ['option' => $record['id']]))),
            Action::make('archive')->label(__('school.archive'))->color('danger')->requiresConfirmation()->modalDescription(__('admission.archive_option_help'))
                ->visible(fn (array $record) => !$record['archived_at'] && !$record['reserved'] && $this->writable('crm.lead.update'))->schema([$this->reason()])
                ->action(fn (array $record, array $data) => SchoolScreen::change('crm.lead.update',
                    fn () => SchoolScreen::call('crm.lead.update', LeadOptionController::class, 'archive', $data, ['option' => $record['id']]))),
        ]);
    }
}
