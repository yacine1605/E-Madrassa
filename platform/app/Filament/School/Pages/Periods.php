<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\CalendarController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class Periods extends ReferencePage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 80;

    public static function getNavigationLabel(): string { return __('reference.periods'); }
    public function getTitle(): string { return __('reference.periods'); }

    public function table(Table $table): Table
    {
        return $table->records(function (?string $search, array $filters, int $page, int $recordsPerPage) {
            $rows = collect($this->years(true))->flatMap(fn ($year) => collect($year['periods'])
                ->map(fn ($period) => [...$period, 'year_code' => $year['code'], 'year_status' => $year['status']]))->all();
            if ($year = $filters['year_id']['value'] ?? null) $rows = array_values(array_filter($rows, fn ($row) => $row['year_id'] === $year));
            return $this->catalogPage($this->filterArchived($rows, $filters), $search, $page, $recordsPerPage);
        })->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->columns([
            TextColumn::make('year_code')->label(__('reference.year')),
            TextColumn::make('code')->label(__('school.code'))->searchable(),
            TextColumn::make('labels')->label(__('school.name'))->state(fn (array $record) => Locales::label($record['labels'])),
            TextColumn::make('start_date')->label(__('reference.start_date'))->formatStateUsing(fn ($state) => Formats::date($state)),
            TextColumn::make('end_date')->label(__('reference.end_date'))->formatStateUsing(fn ($state) => Formats::date($state)),
            TextColumn::make('sort_order')->label(__('reference.sort_order')),
            TextColumn::make('archived_at')->label(__('school.status'))->state(fn (array $record) => __('reference.archive_states.'.($record['archived_at'] ? 'archived' : 'active'))),
        ])->filters([
            SelectFilter::make('year_id')->label(__('reference.year'))->options(fn () => collect($this->years())->pluck('code', 'id')->all()),
            $this->archiveFilter(),
        ])->headerActions([
            Action::make('create')->label(__('reference.create_period'))
                ->visible(fn () => $this->writable('referential.node.manage') && collect($this->years())->contains(fn ($year) => $year['status'] !== 'closed'))
                ->schema([Select::make('year_id')->label(__('reference.year'))->required()->options(fn () => collect($this->years())->where('status', '!=', 'closed')->pluck('code', 'id')->all()),
                    $this->codeField(), ...$this->fields()])
                ->action(fn (array $data) => SchoolScreen::change('referential.node.manage',
                    fn () => SchoolScreen::call('referential.node.manage', CalendarController::class, 'storePeriod', $data, ['year' => $data['year_id']]))),
        ])->recordActions([
            Action::make('edit')->label(__('school.edit'))->visible(fn (array $record) => $this->editable($record))
                ->fillForm(fn (array $record) => $record)->schema([...$this->fields(), Hidden::make('version')->required()])
                ->action(fn (array $record, array $data) => SchoolScreen::change('referential.node.manage',
                    fn () => SchoolScreen::call('referential.node.manage', CalendarController::class, 'updatePeriod', $data, ['period' => $record['id']]))),
            Action::make('archive')->label(__('school.archive'))->color('danger')->requiresConfirmation()->visible(fn (array $record) => $this->editable($record))
                ->schema([$this->reason()])->action(fn (array $record, array $data) => SchoolScreen::change('referential.node.manage',
                    fn () => SchoolScreen::call('referential.node.manage', CalendarController::class, 'archivePeriod', $data, ['period' => $record['id']]))),
        ]);
    }

    private function years(bool $archived = false): array
    {
        return SchoolScreen::call('referential.node.read', CalendarController::class, 'index', ['include_archived' => $archived])['data'];
    }

    private function fields(): array
    {
        return [...$this->labelFields(), DatePicker::make('start_date')->label(__('reference.start_date'))->required()->displayFormat('d/m/Y'),
            DatePicker::make('end_date')->label(__('reference.end_date'))->required()->afterOrEqual('start_date')->displayFormat('d/m/Y'), $this->orderField(1000)];
    }

    private function editable(array $record): bool
    {
        return !$record['archived_at'] && $record['year_status'] !== 'closed' && $this->writable('referential.node.manage');
    }
}
