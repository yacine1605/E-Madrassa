<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\GuardianController;
use App\Support\Formats;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;

/**
 * FR-ELV-02: the guardians, one record for brothers and sisters; linked to a pupil from the pupil's
 * sheet. A new one is first looked for among those recorded (same name, same phone or e-mail).
 */
final class Guardians extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 4;
    protected static array $permissions = ['guardian.record.read'];
    protected static ?string $module = 'students_crm';
    protected static string|array $routeMiddleware = ['module:students_crm', 'permission:guardian.record.read'];
    /** The probable duplicates of the guardian being created, once found. */
    public array $duplicates = [];

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
                Action::make('create')->label(__('schooling.create_guardian'))->visible(fn () => $this->creatable('guardian.record.create'))
                    ->mountUsing(function (?Schema $schema = null) {
                        $this->duplicates = [];
                        $schema?->fill();
                    })
                    ->schema(fn () => [...$this->guardianFields(), $this->siteField('guardian.record.create'), ...$this->customFields('guardian'),
                        Callout::make(__('schooling.guardian_duplicates_found'))->warning()->visible(fn () => $this->duplicates !== [])
                            ->description(fn () => Formats::list(array_map(fn (array $row) => $this->guardianLine($row), $this->duplicates))),
                        Textarea::make('duplicate_reason')->label(__('schooling.guardian_duplicate_reason'))->helperText(__('schooling.duplicate_reason_help'))
                            ->visible(fn () => $this->duplicates !== [])->required(fn () => $this->duplicates !== [])->minLength(3)->maxLength(500)])
                    ->action(function (array $data) {
                        // FR-ELV-02: the same guardian typed again is found first; the API refuses them without a reason anyway.
                        if (($data['duplicate_reason'] ?? '') === '') {
                            unset($data['duplicate_reason']);
                            $this->duplicates = $this->guardianDuplicates($data);
                            if ($this->duplicates !== []) {
                                Notification::make()->title(__('schooling.guardian_duplicates_found'))->warning()->send();
                                throw new Halt();
                            }
                        }
                        SchoolScreen::changeAt('guardian.record.create', $data['site_id'] ?? null,
                            fn () => SchoolScreen::call('guardian.record.create', GuardianController::class, 'store', $this->sentPerson($data, 'guardian')));
                        $this->duplicates = [];
                    }),
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
