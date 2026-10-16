<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\ProgramController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * FR-PRG-01: what one enrols in, for a year: a level of the referential, a price whose every
 * change is a new version that enrolments already made keep out of (BR-11). The payment terms'
 * model comes with the finance of lot 3; until then the API keeps what it is given.
 */
final class Programs extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 5;
    protected static array $permissions = ['program.program.read'];
    protected static ?string $module = 'enrollment_groups';
    protected static string|array $routeMiddleware = ['module:enrollment_groups', 'permission:program.program.read'];

    public static function getNavigationLabel(): string { return __('schooling.programs'); }
    public function getTitle(): string { return __('schooling.programs'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (?string $search, array $filters, int $page, int $recordsPerPage) => $this->apiPage('program.program.read',
            ProgramController::class, 'index', array_filter(['q' => $search, 'year_id' => $filters['year_id']['value'] ?? null,
                'node_id' => $filters['node_id']['value'] ?? null, 'status' => $filters['status']['value'] ?? null]), $page, $recordsPerPage))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->columns([
                TextColumn::make('code')->label(__('school.code'))->searchable(),
                TextColumn::make('labels')->label(__('school.name'))->state(fn (array $record) => Locales::label($record['labels'])),
                TextColumn::make('year_id')->label(__('reference.year'))->formatStateUsing(fn (string $state) => $this->years()[$state]['code'] ?? ''),
                TextColumn::make('node_id')->label(__('schooling.level'))->formatStateUsing(fn (string $state) => $this->nodeName($state) ?? ''),
                TextColumn::make('base_price')->label(__('schooling.price'))->formatStateUsing(fn ($state) => $this->money($state)),
                TextColumn::make('duration_value')->label(__('schooling.duration'))->state(fn (array $record) => $record['duration_value']
                    ? Formats::number($record['duration_value']).' '.__('schooling.duration_units.'.$record['duration_unit']) : ''),
                TextColumn::make('status')->label(__('school.status'))->badge()->formatStateUsing(fn (string $state) => __('schooling.program_states.'.$state)),
            ])->filters([
                SelectFilter::make('year_id')->label(__('reference.year'))->options(fn () => collect($this->years())->pluck('code', 'id')->all()),
                SelectFilter::make('node_id')->label(__('schooling.level'))->searchable()
                    ->options(fn () => collect($this->nodes())->mapWithKeys(fn ($node) => [$node['id'] => $this->nodeName($node['id'])])->all()),
                SelectFilter::make('status')->label(__('school.status'))->options(__('schooling.program_states')),
            ])->headerActions([
                Action::make('create')->label(__('schooling.create_program'))->visible(fn () => $this->writable('program.program.manage') && $this->openYears() !== [])
                    ->schema([
                        Select::make('year_id')->label(__('reference.year'))->required()->options(fn () => $this->openYears()),
                        Select::make('node_id')->label(__('schooling.level'))->required()->searchable()->helperText(__('schooling.level_help'))
                            ->options(fn () => collect($this->nodes())->whereNull('archived_at')->mapWithKeys(fn ($node) => [$node['id'] => $this->nodeName($node['id'])])->all()),
                        $this->codeField(), ...$this->fields(),
                    ])
                    ->action(fn (array $data) => SchoolScreen::change('program.program.manage',
                        fn () => SchoolScreen::call('program.program.manage', ProgramController::class, 'store', $this->sent($data)))),
            ])->recordUrl(fn (array $record) => ProgramSubjects::getUrl(['program' => $record['id']]))->recordActions([
                // FR-PRG-02: its subjects and their volumes.
                Action::make('subjects')->label(__('schooling.program_subjects'))->url(fn (array $record) => ProgramSubjects::getUrl(['program' => $record['id']])),
                Action::make('prices')->label(__('schooling.prices'))->modalHeading(__('schooling.prices'))->modalSubmitAction(false)
                    ->modalContent(fn (array $record) => view('filament.school.program-prices', ['prices' => array_map(fn ($price) => [
                        'version' => Formats::number($price['version']), 'price' => $this->money($price['base_price']), 'since' => Formats::dateTime($price['created_at']),
                    ], SchoolScreen::call('program.program.read', ProgramController::class, 'prices', ['paginate' => true, 'per_page' => 100], ['program' => $record['id']])['data'])])),
                Action::make('edit')->label(__('school.edit'))
                    ->visible(fn (array $record) => !$this->yearClosed($record['year_id']) && $this->writableFor('program.program.manage', 'program', $record['id']))
                    ->fillForm(fn (array $record) => $record)->schema([...$this->fields(true), Hidden::make('version')->required()])
                    ->action(fn (array $record, array $data) => SchoolScreen::changeFor('program.program.manage', 'program', $record['id'],
                        fn () => SchoolScreen::call('program.program.manage', ProgramController::class, 'update', $this->sent($data), ['program' => $record['id']]))),
                // FR-PRG-02: a draft in a later year, with its subjects and volumes.
                Action::make('copy')->label(__('schooling.copy_program'))->modalDescription(__('schooling.copy_program_help'))
                    ->visible(fn (array $record) => $this->writable('program.program.manage') && $this->laterYears($record['year_id']) !== [])
                    ->schema(fn (array $record) => [Select::make('year_id')->label(__('schooling.copy_year'))->required()->options($this->laterYears($record['year_id']))])
                    ->action(fn (array $record, array $data) => SchoolScreen::change('program.program.manage',
                        fn () => SchoolScreen::call('program.program.manage', ProgramController::class, 'copy', $data, ['program' => $record['id']]))),
            ]);
    }

    /** @return array<string, string> the years still open that begin after this one */
    private function laterYears(string $year): array
    {
        $start = $this->years()[$year]['start_date'] ?? null;
        return collect($this->years())->filter(fn (array $other) => $start !== null && $other['start_date'] > $start && $other['status'] !== 'closed')
            ->pluck('code', 'id')->all();
    }

    private function fields(bool $existing = false): array
    {
        return [...$this->labelFields(), Textarea::make('description')->label(__('reference.description'))->maxLength(2000),
            TextInput::make('duration_value')->label(__('schooling.duration'))->numeric()->integer()->minValue(1)->requiredWith('duration_unit'),
            Select::make('duration_unit')->label(__('schooling.duration_unit'))->options(__('schooling.duration_units'))->requiredWith('duration_value'),
            TextInput::make('base_price')->label(__('schooling.price'))->required()->default('0')->suffix(__('formats.currency'))
                ->regex('/^(0|[1-9][0-9]{0,11})(\.[0-9]{1,2})?$/')->helperText(__($existing ? 'schooling.price_version_help' : 'schooling.price_help')),
            Select::make('status')->label(__('school.status'))->required()->default('draft')->options(__('schooling.program_states'))
                ->helperText(__('schooling.program_status_help'))];
    }

    /** An empty duration is no duration; a price is sent as written, never through a float. */
    private function sent(array $data): array
    {
        $data['duration_value'] = ($data['duration_value'] ?? '') === '' ? null : (int) $data['duration_value'];
        $data['duration_unit'] = $data['duration_unit'] ?? null;
        $data['base_price'] = (string) $data['base_price'];
        return $data;
    }
}
