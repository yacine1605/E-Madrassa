<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\StudentController;
use App\Support\Formats;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * FR-ELV-01, FR-ELV-04: the pupils' records, searched by name in either alphabet, matricule,
 * phone or birth date; each opens on its sheet. Creating one first looks for a probable
 * duplicate: the existing record opens, or the new one is made with a reason kept in the journal.
 */
final class Students extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 3;
    protected static array $permissions = ['student.record.read'];
    protected static ?string $module = 'students_crm';
    protected static string|array $routeMiddleware = ['module:students_crm', 'permission:student.record.read'];
    /** The probable duplicates of the record being created, once found (FR-ELV-04). */
    public array $duplicates = [];

    public static function getNavigationLabel(): string { return __('schooling.students'); }
    public function getTitle(): string { return __('schooling.students'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (?string $search, array $filters, int $page, int $recordsPerPage) => $this->apiPage('student.record.read',
            StudentController::class, 'index', array_filter(['q' => $search, 'status' => $filters['status']['value'] ?? null,
                'birth_date' => $filters['birth']['birth_date'] ?? null, 'include_archived' => ($filters['archived']['isActive'] ?? false) ?: null]), $page, $recordsPerPage))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)
            ->recordUrl(fn (array $record) => StudentSheet::getUrl(['student' => $record['id']]))
            ->columns([
                TextColumn::make('matricule')->label(__('schooling.matricule'))->searchable(),
                TextColumn::make('name')->label(__('school.name'))->state(fn (array $record) => $this->personName($record)),
                TextColumn::make('birth_date')->label(__('schooling.birth_date'))->formatStateUsing(fn ($state) => Formats::date($state)),
                TextColumn::make('phone')->label(__('schooling.phone'))->formatStateUsing(fn (?string $state) => Formats::isolated($state)),
                TextColumn::make('status')->label(__('school.status'))->badge()
                    ->state(fn (array $record) => $record['archived_at'] ? 'archived' : $record['status'])
                    ->formatStateUsing(fn (string $state) => __('schooling.student_states.'.$state)),
            ])->filters([
                SelectFilter::make('status')->label(__('school.status'))->options(collect(__('schooling.student_states'))->except('archived')->all()),
                Filter::make('birth')->schema([DatePicker::make('birth_date')->label(__('schooling.birth_date'))]),
                Filter::make('archived')->label(__('schooling.include_archived'))->toggle(),
            ])->headerActions([
                Action::make('create')->label(__('schooling.create_student'))->visible(fn () => $this->writable('student.record.create'))
                    ->mountUsing(function (?Schema $schema = null) {
                        $this->duplicates = [];
                        $schema?->fill();
                    })
                    ->schema(fn () => [...$this->studentFields(),
                        TextInput::make('matricule')->label(__('schooling.matricule'))->regex('/^[A-Za-z0-9_-]{1,30}$/')->helperText(__('schooling.matricule_help')),
                        ...$this->customFields('student'),
                        Callout::make(__('schooling.duplicates_found'))->warning()->visible(fn () => $this->duplicates !== [])
                            ->description(fn () => Formats::list(array_map(fn ($row) => $row['matricule'].' · '.$this->personName($row)
                                .' · '.Formats::date($row['birth_date']).' ('.__('schooling.duplicate_matches.'.$row['match']).')', $this->duplicates))),
                        Textarea::make('duplicate_reason')->label(__('schooling.duplicate_reason'))->helperText(__('schooling.duplicate_reason_help'))
                            ->visible(fn () => $this->duplicates !== [])->required(fn () => $this->duplicates !== [])->minLength(3)->maxLength(500)])
                    ->extraModalFooterActions(fn () => $this->duplicates === [] ? [] : [Action::make('openExisting')->label(__('schooling.open_existing'))
                        ->color('gray')->url(StudentSheet::getUrl(['student' => $this->duplicates[0]['id']]))])
                    ->action(function (array $data) {
                        $data = $this->sentStudent($data);
                        if (($data['duplicate_reason'] ?? '') === '') {
                            unset($data['duplicate_reason']);
                            // FR-ELV-04: warn first; the API refuses the same duplicate without a reason anyway.
                            $this->duplicates = SchoolScreen::call('student.record.read', StudentController::class, 'duplicates', $data)['data'];
                            if ($this->duplicates !== []) {
                                Notification::make()->title(__('schooling.duplicates_found'))->warning()->send();
                                throw new Halt();
                            }
                        }
                        $created = SchoolScreen::change('student.record.create', fn () => SchoolScreen::call('student.record.create', StudentController::class, 'store', $data),
                            fn (array $result) => Notification::make()->title(__('schooling.student_created', ['matricule' => $result['data']['matricule']]))->success());
                        $this->duplicates = [];
                        $this->redirect(StudentSheet::getUrl(['student' => $created['data']['id']]));
                    }),
            ])->recordActions([
                Action::make('open')->label(__('schooling.open'))->url(fn (array $record) => StudentSheet::getUrl(['student' => $record['id']])),
            ]);
    }
}
