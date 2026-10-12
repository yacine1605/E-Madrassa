<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\ProgramController;
use App\Http\Controllers\StudyGroupController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * FR-GRP-01: classes, support and option groups, training sessions, each on a site for a year,
 * with its places. A class or a session is the main group of its programme's pupils; fewer
 * places than members is refused (AC2), and a closed group keeps its composition.
 */
final class Groups extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 6;
    protected static array $permissions = ['group.group.read'];
    protected static ?string $module = 'enrollment_groups';
    protected static string|array $routeMiddleware = ['module:enrollment_groups', 'permission:group.group.read'];
    // From one state to the next, as the database allows them.
    private const NEXT = ['draft' => ['draft', 'active', 'closed'], 'active' => ['active', 'closed'], 'closed' => ['closed']];

    public static function getNavigationLabel(): string { return __('schooling.groups'); }
    public function getTitle(): string { return __('schooling.groups'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (?string $search, array $filters, int $page, int $recordsPerPage) => $this->apiPage('group.group.read',
            StudyGroupController::class, 'index', array_filter(['q' => $search, 'year_id' => $filters['year_id']['value'] ?? null,
                'site_id' => $filters['site_id']['value'] ?? null, 'group_type' => $filters['group_type']['value'] ?? null,
                'status' => $filters['status']['value'] ?? null]), $page, $recordsPerPage))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->columns([
                TextColumn::make('code')->label(__('school.code'))->searchable(),
                TextColumn::make('labels')->label(__('school.name'))->state(fn (array $record) => Locales::label($record['labels'])),
                TextColumn::make('group_type')->label(__('schooling.group_type'))->formatStateUsing(fn (string $state) => __('schooling.group_types.'.$state)),
                TextColumn::make('program_code')->label(__('schooling.program')),
                TextColumn::make('site_id')->label(__('schooling.site'))->formatStateUsing(fn (string $state) => $this->siteName($state) ?? ''),
                TextColumn::make('year_id')->label(__('reference.year'))->formatStateUsing(fn (string $state) => $this->years()[$state]['code'] ?? ''),
                TextColumn::make('members')->label(__('schooling.places'))
                    ->state(fn (array $record) => __('schooling.places_taken', ['members' => Formats::number($record['members']), 'capacity' => Formats::number($record['capacity'])])),
                TextColumn::make('valid_from')->label(__('school.valid_from'))->formatStateUsing(fn ($state) => Formats::date($state)),
                TextColumn::make('valid_to')->label(__('school.valid_until'))->formatStateUsing(fn ($state) => Formats::date($state)),
                TextColumn::make('status')->label(__('school.status'))->badge()->formatStateUsing(fn (string $state) => __('schooling.group_states.'.$state)),
            ])->filters([
                SelectFilter::make('year_id')->label(__('reference.year'))->options(fn () => collect($this->years())->pluck('code', 'id')->all()),
                SelectFilter::make('site_id')->label(__('schooling.site'))->options(fn () => collect($this->sites())->mapWithKeys(fn ($site) => [$site['id'] => $this->siteName($site['id'])])->all()),
                SelectFilter::make('group_type')->label(__('schooling.group_type'))->options(__('schooling.group_types')),
                SelectFilter::make('status')->label(__('school.status'))->options(__('schooling.group_states')),
            ])->headerActions([
                Action::make('create')->label(__('schooling.create_group'))->visible(fn () => $this->writable('group.group.manage') && $this->openYears() !== [])
                    ->schema([
                        Select::make('year_id')->label(__('reference.year'))->required()->live()->options(fn () => $this->openYears())
                            ->afterStateUpdated(fn (Set $set) => $set('program_id', null)),
                        Select::make('group_type')->label(__('schooling.group_type'))->required()->live()->default('class')->options(__('schooling.group_types'))
                            ->helperText(__('schooling.group_type_help')),
                        Select::make('program_id')->label(__('schooling.program'))->searchable()
                            ->required(fn (Get $get) => in_array($get('group_type'), ['class', 'session'], true))
                            ->getSearchResultsUsing(fn (string $search, Get $get) => $this->programOptions($get('year_id'), $search))
                            ->getOptionLabelUsing(fn ($value) => $this->programLabel($value)),
                        Select::make('site_id')->label(__('schooling.site'))->required()->default(fn () => collect($this->sites())->firstWhere('is_default', true)['id'] ?? null)
                            ->options(fn () => collect($this->sites())->whereNull('archived_at')->mapWithKeys(fn ($site) => [$site['id'] => $this->siteName($site['id'])])->all()),
                        $this->codeField(), ...$this->labelFields(), $this->capacityField(),
                        DatePicker::make('valid_from')->label(__('school.valid_from'))->required()->default(now()),
                        DatePicker::make('valid_to')->label(__('school.valid_until'))->afterOrEqual('valid_from'),
                        Select::make('status')->label(__('school.status'))->required()->default('draft')
                            ->options(collect(__('schooling.group_states'))->only(['draft', 'active'])->all()),
                    ])
                    ->action(function (array $data) {
                        if (!in_array($data['group_type'], ['class', 'session'], true) && empty($data['program_id'])) unset($data['program_id']);
                        SchoolScreen::change('group.group.manage', fn () => SchoolScreen::call('group.group.manage', StudyGroupController::class, 'store', $this->sent($data)));
                    }),
            ])->recordActions([
                Action::make('edit')->label(__('school.edit'))
                    ->visible(fn (array $record) => $record['status'] !== 'closed' && !$this->yearClosed($record['year_id'])
                        && $this->writableFor('group.group.manage', 'study_group', $record['id']))
                    ->fillForm(fn (array $record) => $record)
                    ->schema(fn (array $record) => [...$this->labelFields(), $this->capacityField()->helperText(__('schooling.capacity_help')),
                        DatePicker::make('valid_to')->label(__('school.valid_until'))->minDate($record['valid_from']),
                        Select::make('status')->label(__('school.status'))->required()
                            ->options(collect(__('schooling.group_states'))->only(self::NEXT[$record['status']])->all()),
                        Hidden::make('version')->required()])
                    ->action(fn (array $record, array $data) => SchoolScreen::changeFor('group.group.manage', 'study_group', $record['id'],
                        fn () => SchoolScreen::call('group.group.manage', StudyGroupController::class, 'update', $this->sent($data), ['group' => $record['id']]))),
            ]);
    }

    private function capacityField(): TextInput
    {
        return TextInput::make('capacity')->label(__('schooling.capacity'))->required()->numeric()->integer()->minValue(1)->maxValue(100000);
    }

    /** @return array<string, string> the year's programmes matching the search, at most 50 */
    private function programOptions(?string $year, string $search): array
    {
        if (!$year) return [];
        return collect(SchoolScreen::call('program.program.read', ProgramController::class, 'index', ['year_id' => $year, 'q' => $search, 'per_page' => 50])['data'])
            ->mapWithKeys(fn ($program) => [$program['id'] => $program['code'].' · '.Locales::label($program['labels'])])->all();
    }

    private function programLabel(?string $program): ?string
    {
        if (!$program) return null;
        $row = SchoolScreen::call('program.program.read', ProgramController::class, 'show', parameters: ['program' => $program])['data'];
        return $row['code'].' · '.Locales::label($row['labels']);
    }

    /** Dates as the API reads them; an empty end is no end. */
    private function sent(array $data): array
    {
        foreach (['valid_from', 'valid_to'] as $field) {
            if (array_key_exists($field, $data)) $data[$field] = $data[$field] ? substr((string) $data[$field], 0, 10) : null;
        }
        $data['capacity'] = (int) $data['capacity'];
        return $data;
    }
}
