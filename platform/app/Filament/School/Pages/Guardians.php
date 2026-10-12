<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\GuardianController;
use App\Support\Formats;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;

/** FR-ELV-02: the guardians, one record for brothers and sisters; linked to a pupil from the pupil's sheet. */
final class Guardians extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 4;
    protected static array $permissions = ['guardian.record.read'];
    protected static ?string $module = 'students_crm';
    protected static string|array $routeMiddleware = ['module:students_crm', 'permission:guardian.record.read'];

    public static function getNavigationLabel(): string { return __('schooling.guardians'); }
    public function getTitle(): string { return __('schooling.guardians'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (?string $search, array $filters, int $page, int $recordsPerPage) => $this->apiPage('guardian.record.read',
            GuardianController::class, 'index', array_filter(['q' => $search, 'include_archived' => ($filters['archived']['isActive'] ?? false) ?: null]), $page, $recordsPerPage))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->columns([
                TextColumn::make('name')->label(__('school.name'))->state(fn (array $record) => $this->personName($record))->searchable(),
                TextColumn::make('phone')->label(__('schooling.phone'))->formatStateUsing(fn (?string $state) => Formats::isolated($state)),
                TextColumn::make('email')->label(__('school.email'))->formatStateUsing(fn (?string $state) => Formats::isolated($state)),
                TextColumn::make('profession')->label(__('schooling.profession'))->formatStateUsing(fn (?string $state) => Formats::isolated($state)),
            ])->filters([Filter::make('archived')->label(__('schooling.include_archived'))->toggle()])
            ->headerActions([
                Action::make('create')->label(__('schooling.create_guardian'))->visible(fn () => $this->writable('guardian.record.create'))
                    ->schema(fn () => [...$this->guardianFields(), ...$this->customFields('guardian')])
                    ->action(fn (array $data) => SchoolScreen::change('guardian.record.create',
                        fn () => SchoolScreen::call('guardian.record.create', GuardianController::class, 'store', $this->sentPerson($data, 'guardian')))),
            ])->recordActions([
                Action::make('edit')->label(__('school.edit'))
                    ->visible(fn (array $record) => !$record['archived_at'] && $this->writableFor('guardian.record.update', 'guardian', $record['id']))
                    ->fillForm(fn (array $record) => $record)
                    ->schema(fn () => [...$this->guardianFields(), ...$this->customFields('guardian'), Hidden::make('version')->required()])
                    ->action(fn (array $record, array $data) => SchoolScreen::changeFor('guardian.record.update', 'guardian', $record['id'],
                        fn () => SchoolScreen::call('guardian.record.update', GuardianController::class, 'update', $this->sentPerson($data, 'guardian'), ['guardian' => $record['id']]))),
            ]);
    }
}
