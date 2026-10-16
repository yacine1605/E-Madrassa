<?php

namespace App\Filament\School\Pages;

use App\Filament\School\Pages\Concerns\FollowsLeads;
use App\Http\Controllers\LeadController;
use App\Support\Formats;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * FR-CRM-01: the families who make contact, until the enrolment: searched by the contact's or a
 * child's name, phone or e-mail; the open ones first. Recording one first looks for the same
 * phone among prospects, pupils and guardians; each opens on its sheet.
 */
final class Leads extends SchoolingPage
{
    use FollowsLeads;

    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 2;
    protected static array $permissions = ['crm.lead.read'];
    protected static ?string $module = 'students_crm';
    protected static string|array $routeMiddleware = ['module:students_crm', 'permission:crm.lead.read'];
    /** Who already has the phone being recorded, once found. */
    public array $duplicates = [];

    public static function getNavigationLabel(): string { return __('admission.leads'); }
    public function getTitle(): string { return __('admission.leads'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (?string $search, array $filters, int $page, int $recordsPerPage) => $this->apiPage('crm.lead.read',
            LeadController::class, 'index', array_filter(['q' => $search, 'status' => $filters['status']['value'] ?? null, 'source' => $filters['source']['value'] ?? null,
                'site_id' => $filters['site_id']['value'] ?? null, 'mine' => ($filters['mine']['isActive'] ?? false) ?: null,
                'open' => ($filters['closed']['isActive'] ?? false) ? null : true]), $page, $recordsPerPage))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)
            ->emptyStateHeading(__('admission.no_lead'))->emptyStateDescription(__('admission.no_lead_help'))
            ->recordUrl(fn (array $record) => LeadSheet::getUrl(['lead' => $record['id']]))
            ->columns([
                TextColumn::make('contact')->label(__('admission.contact'))->searchable()->state(fn (array $record) => $this->contactName($record))
                    ->description(fn (array $record) => Formats::isolated($record['phone'] ?? $record['email'])),
                TextColumn::make('children')->label(__('admission.children'))->wrap()
                    ->state(fn (array $record) => Formats::list(array_map(fn (array $child) => $this->childName($child), $record['children']))),
                TextColumn::make('source')->label(__('admission.source'))->formatStateUsing(fn (string $state) => $this->leadOptionName('source', $state)),
                TextColumn::make('status')->label(__('admission.status'))->badge()->formatStateUsing(fn (string $state) => $this->leadOptionName('status', $state))
                    ->color(fn (string $state) => match ($state) { 'registered' => 'success', 'lost' => 'gray', default => 'warning' })
                    ->description(fn (array $record) => $record['closed_at'] ? __('admission.closed_on', ['date' => Formats::date($record['closed_at'])]) : null),
                TextColumn::make('assigned_name')->label(__('admission.assigned_to')),
                TextColumn::make('next_reminder')->label(__('admission.next_reminder'))->formatStateUsing(fn ($state) => Formats::dateTime($state)),
                TextColumn::make('site_id')->label(__('schooling.site'))->formatStateUsing(fn (string $state) => $this->siteName($state) ?? ''),
            ])->filters([
                SelectFilter::make('status')->label(__('admission.status'))->options(fn () => $this->leadOptions('status')),
                SelectFilter::make('source')->label(__('admission.source'))->options(fn () => $this->leadOptions('source')),
                SelectFilter::make('site_id')->label(__('schooling.site'))->options(fn () => collect($this->sites())->mapWithKeys(fn ($site) => [$site['id'] => $this->siteName($site['id'])])->all()),
                Filter::make('mine')->label(__('admission.mine'))->toggle(),
                Filter::make('closed')->label(__('admission.include_closed'))->toggle(),
            ])->headerActions([
                Action::make('options')->label(__('admission.options'))->color('gray')->url(fn () => LeadOptions::getUrl()),
                Action::make('create')->label(__('admission.create_lead'))->visible(fn () => $this->creatable('crm.lead.create'))
                    ->mountUsing(function (?Schema $schema = null) {
                        $this->duplicates = [];
                        $schema?->fill();
                    })
                    ->schema(fn () => [$this->siteField('crm.lead.create')->live(), ...$this->contactFields(),
                        Select::make('source')->label(__('admission.source'))->required()->options(fn () => $this->leadOptions('source')),
                        Select::make('assigned_to')->label(__('admission.assigned_to'))->helperText(__('admission.assigned_help'))
                            ->options(fn (Get $get) => $this->assignees($get('site_id'))),
                        Repeater::make('children')->label(__('admission.children'))->schema($this->childFields())->columns(2)->defaultItems(1)->maxItems(10)
                            ->addActionLabel(__('admission.add_child')),
                        ...$this->customFields('lead'),
                        Callout::make(__('admission.duplicates_found'))->warning()->visible(fn () => $this->duplicates !== [])
                            ->description(fn () => Formats::list(array_map(fn (array $row) => $row['name'].' ('.__('admission.duplicate_kinds.'.$row['kind']).')', $this->duplicates))),
                        Textarea::make('duplicate_reason')->label(__('admission.duplicate_reason'))->helperText(__('admission.duplicate_reason_help'))
                            ->visible(fn () => $this->duplicates !== [])->required(fn () => $this->duplicates !== [])->minLength(3)->maxLength(500)])
                    ->action(function (array $data) {
                        $data = $this->sentPerson($data, 'lead');
                        $data['children'] = array_values(array_map(fn (array $child) => $this->sentChild($child), $data['children'] ?? []));
                        if (($data['duplicate_reason'] ?? null) === null) {
                            unset($data['duplicate_reason']);
                            // Warn first; the API refuses the same phone without a reason anyway.
                            $this->duplicates = isset($data['phone']) ? SchoolScreen::call('crm.lead.read', LeadController::class, 'duplicates', ['phone' => $data['phone']])['data'] : [];
                            if ($this->duplicates !== []) {
                                Notification::make()->title(__('admission.duplicates_found'))->warning()->send();
                                throw new Halt();
                            }
                        }
                        $created = SchoolScreen::changeAt('crm.lead.create', $data['site_id'] ?? null,
                            fn () => SchoolScreen::call('crm.lead.create', LeadController::class, 'store', $data));
                        $this->duplicates = [];
                        $this->redirect(LeadSheet::getUrl(['lead' => $created['data']['id']]));
                    }),
            ])->recordActions([
                Action::make('open')->label(__('admission.open'))->url(fn (array $record) => LeadSheet::getUrl(['lead' => $record['id']])),
            ]);
    }
}
