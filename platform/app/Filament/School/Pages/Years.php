<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\CalendarController;
use App\Support\Formats;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class Years extends ReferencePage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 70;

    public static function getNavigationLabel(): string { return __('reference.years'); }
    public function getTitle(): string { return __('reference.years'); }

    public function table(Table $table): Table
    {
        return $table->records(function (?string $search, array $filters, int $page, int $recordsPerPage) {
            $rows = SchoolScreen::call('referential.node.read', CalendarController::class, 'index')['data'];
            if ($status = $filters['status']['value'] ?? null) $rows = array_values(array_filter($rows, fn ($row) => $row['status'] === $status));
            return $this->catalogPage($rows, $search, $page, $recordsPerPage);
        })->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->columns([
            TextColumn::make('code')->label(__('school.code'))->searchable(),
            TextColumn::make('start_date')->label(__('reference.start_date'))->formatStateUsing(fn ($state) => Formats::date($state)),
            TextColumn::make('end_date')->label(__('reference.end_date'))->formatStateUsing(fn ($state) => Formats::date($state)),
            TextColumn::make('status')->label(__('school.status'))->badge()->formatStateUsing(fn (string $state) => __('reference.year_states.'.$state)),
        ])->filters([SelectFilter::make('status')->label(__('school.status'))->options(__('reference.year_states'))])->headerActions([
            Action::make('create')->label(__('reference.create_year'))->visible(fn () => $this->writable('referential.node.manage'))
                ->schema([TextInput::make('code')->label(__('school.code'))->required()->regex('/^[A-Za-z0-9_-]{1,20}$/')->helperText(__('reference.year_code')),
                    ...$this->dates(), Select::make('status')->label(__('school.status'))->required()->default('planned')->options(collect(__('reference.year_states'))->except('closed')->all())])
                ->action(fn (array $data) => SchoolScreen::change('referential.node.manage',
                    fn () => SchoolScreen::call('referential.node.manage', CalendarController::class, 'store', $data))),
        ])->recordActions([
            Action::make('periods')->label(__('reference.periods'))->url(fn (array $record) => Periods::getUrl(['tableFilters' => ['year_id' => ['value' => $record['id']]]])),
            Action::make('edit')->label(__('school.edit'))->visible(fn (array $record) => $record['status'] !== 'closed' && $this->writable('referential.node.manage'))
                ->fillForm(fn (array $record) => $record)->schema([...$this->dates(), Hidden::make('version')->required()])
                ->action(fn (array $record, array $data) => SchoolScreen::change('referential.node.manage',
                    fn () => SchoolScreen::call('referential.node.manage', CalendarController::class, 'update', $data, ['year' => $record['id']]))),
            Action::make('makeCurrent')->label(__('reference.make_current'))->requiresConfirmation()
                ->visible(fn (array $record) => $record['status'] === 'planned' && $this->writable('referential.node.manage'))
                ->action(fn (array $record) => SchoolScreen::change('referential.node.manage',
                    fn () => SchoolScreen::call('referential.node.manage', CalendarController::class, 'status', ['status' => 'current'], ['year' => $record['id']]))),
            Action::make('close')->label(__('reference.close_year'))->requiresConfirmation()->color('danger')->modalDescription(__('reference.close_help'))
                ->visible(fn (array $record) => $record['status'] !== 'closed' && $this->writable('referential.node.manage'))->schema([$this->reason()])
                ->action(fn (array $record, array $data) => SchoolScreen::change('referential.node.manage',
                    fn () => SchoolScreen::call('referential.node.manage', CalendarController::class, 'status', [...$data, 'status' => 'closed'], ['year' => $record['id']]))),
        ]);
    }

    private function dates(): array
    {
        return [DatePicker::make('start_date')->label(__('reference.start_date'))->required()->displayFormat('d/m/Y'),
            DatePicker::make('end_date')->label(__('reference.end_date'))->required()->after('start_date')->displayFormat('d/m/Y')];
    }
}
