<?php

namespace App\Filament\School\Pages;

use App\Filament\School\Pages\Concerns\EnrolsPupils;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\GuardianController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentPhotoController;
use App\Support\Formats;
use App\Support\InitialsAvatar;
use App\Support\Locales;
use App\Support\Permissions;
use App\Support\SchoolScreen;
use App\Support\StudentPhotos;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * FR-ELV-01 to FR-ELV-03: one pupil's sheet. Its identity and school's fields, its changes of
 * state with their reason, and its guardians: one record for brothers and sisters, linked to
 * each with the relationship, the primary contact, the payer and the emergency contact.
 */
final class StudentSheet extends SchoolingPage
{
    use EnrolsPupils;

    protected static bool $isDiscovered = true;
    protected static bool $shouldRegisterNavigation = false;
    protected static array $permissions = ['student.record.read'];
    protected static ?string $module = 'students_crm';
    protected static string|array $routeMiddleware = ['module:students_crm', 'permission:student.record.read'];
    protected string $view = 'filament.school.student-sheet';
    #[Locked] public string $student = '';
    /** The guardians already recorded who are probably the one being created, once found (FR-ELV-02). */
    public array $sameGuardians = [];
    private ?array $pupilRow = null;

    public function mount(): void
    {
        parent::mount();
        $this->student = (string) request()->query('student', '');
        $this->pupil();
    }

    /** The pupil as the reader may see them; a record out of their reach is not found. */
    public function pupil(): array
    {
        abort_unless(Str::isUuid($this->student), 404);
        return $this->pupilRow ??= SchoolScreen::call('student.record.read', StudentController::class, 'show', parameters: ['student' => $this->student])['data'];
    }

    public function getTitle(): string { return $this->personName($this->pupil()); }

    public function getSubheading(): ?string
    {
        $pupil = $this->pupil();
        return Formats::list(array_filter([__('schooling.matricule').' '.$pupil['matricule'], Formats::date($pupil['birth_date']),
            __('schooling.student_states.'.($pupil['archived_at'] ? 'archived' : $pupil['status']))]));
    }

    /** What the sheet shows above its guardians, in the reader's language. */
    public function identityInfolist(Schema $schema): Schema
    {
        $rows = $this->identity();
        $enrollments = $this->enrollments();
        return $schema->components([Section::make(__('schooling.identity'))->columns(['default' => 1, 'sm' => 2])
            ->schema([
                // FR-ELV-01: the photo's thumbnail, or the initials of a pupil without one.
                ImageEntry::make('photo')->hiddenLabel()->state($this->photoUrl())->alt(__('schooling.photo'))->circular()->imageSize(96)->columnSpanFull(),
                ...array_map(fn (string $label, string $value, int $index) => TextEntry::make('identity_'.$index)->label($label)->state($value),
                    array_keys($rows), array_values($rows), array_keys(array_values($rows))),
            ]),
            // FR-ELV-01: the sheet shows the pupil's enrolments and groups, as far as the reader may see them.
            Section::make(__('schooling.enrollments'))->visible($enrollments !== null)->schema([RepeatableEntry::make('enrollments')->hiddenLabel()
                ->columns(['default' => 2, 'sm' => 4])->placeholder(__('schooling.no_enrollment'))->state(array_map(fn (array $enrollment) => [
                    'program' => $enrollment['terms_snapshot']['code'].' · '.Locales::label($enrollment['terms_snapshot']['labels']),
                    'year' => $this->years()[$enrollment['year_id']]['code'] ?? null, 'status' => __('schooling.enrollment_states.'.$enrollment['status']),
                    'group' => $enrollment['main_group']['code'] ?? null], $enrollments ?? []))
                ->schema([TextEntry::make('program')->label(__('schooling.program')), TextEntry::make('year')->label(__('reference.year')),
                    TextEntry::make('status')->label(__('school.status')), TextEntry::make('group')->label(__('schooling.main_group'))])]),
        ]);
    }

    /** The thumbnail through the pupil's own route (never a public link), named by version so that a new photo shows at once. */
    private function photoUrl(): string
    {
        $pupil = $this->pupil();
        return $pupil['has_photo'] ? url('/api/students/'.$pupil['id'].'/photo?size=thumb&v='.$pupil['version'])
            : InitialsAvatar::of($this->personName($pupil));
    }

    /** @return ?list<array> the pupil's enrolments, or null for a reader without them */
    private function enrollments(): ?array
    {
        if (!app(Permissions::class)->can('enrollment.enrollment.read')) return null;
        return SchoolScreen::call('enrollment.enrollment.read', EnrollmentController::class, 'index', ['student_id' => $this->student, 'per_page' => 100])['data'];
    }

    /** A pupil is enrolled while active (FR-INS-01 AC2, FR-ELV-03). */
    protected function enrollable(): bool
    {
        return $this->pupil()['status'] === 'active' && !$this->pupil()['archived_at'];
    }

    /** @return array<string, string> label and value */
    private function identity(): array
    {
        $pupil = $this->pupil();
        $rows = [__('schooling.birth_place') => $pupil['birth_place'], __('schooling.gender') => $pupil['gender'] ? __('schooling.genders.'.$pupil['gender']) : null,
            __('schooling.phone') => $pupil['phone'], __('school.email') => $pupil['email'], __('schooling.address') => $pupil['address'],
            __('schooling.origin_school') => $pupil['origin_school'], __('schooling.block_reason') => $pupil['status_reason']];
        foreach ($this->customDefinitions('student', true) as $field) {
            $value = $pupil['custom'][$field['key']] ?? null;
            if ($value !== null && $value !== '') $rows[Locales::label($field['labels']) ?? $field['key']] = $this->customValue($field, $value);
        }
        // What people typed keeps its own direction, the house number before the street in Arabic too.
        return array_map(fn ($value) => Formats::isolated($value), array_filter($rows, fn ($value) => $value !== null && $value !== ''));
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->enrolAction($this->student)->after(fn () => $this->changed([])),
            Action::make('enrollments')->label(__('schooling.enrollments'))->color('gray')->visible(fn () => app(Permissions::class)->can('enrollment.enrollment.read'))
                ->url(fn () => Enrollments::getUrl(['tableFilters' => ['student' => ['student_id' => $this->student]]])),
            Action::make('edit')->label(__('school.edit'))->visible(fn () => $this->editable())
                ->fillForm(fn () => $this->pupil())
                ->schema(fn () => [...$this->studentFields(), ...$this->customFields('student'), Hidden::make('version')->required()])
                ->action(fn (array $data) => $this->changed(SchoolScreen::changeFor('student.record.update', 'student', $this->student,
                    fn () => SchoolScreen::call('student.record.update', StudentController::class, 'update', $this->sentStudent($data), ['student' => $this->student])))),
            // FR-ELV-03: active ⇄ blocked with a reason, active → former; a block says how many enrolments it leaves as they are (AC1).
            Action::make('status')->label(__('schooling.change_status'))
                ->visible(fn () => $this->pupil()['status'] !== 'former' && !$this->pupil()['archived_at']
                    && $this->writableFor('student.record.update', 'student', $this->student))
                ->schema(fn () => [
                    Select::make('status')->label(__('school.status'))->required()->live()
                        ->options(collect(__('schooling.student_states'))->only($this->pupil()['status'] === 'active' ? ['former', 'blocked'] : ['active'])->all()),
                    Callout::make(__('schooling.block_enrollments'))->warning()
                        ->visible(fn (Get $get) => $get('status') === 'blocked' && $this->enrollmentCount() > 0)
                        ->description(fn () => __('schooling.block_enrollments_help', ['count' => Formats::number($this->enrollmentCount())])),
                    $this->reason(),
                ])
                ->action(fn (array $data) => $this->changed(SchoolScreen::changeFor('student.record.update', 'student', $this->student,
                    fn () => SchoolScreen::call('student.record.update', StudentController::class, 'status',
                        [...$data, 'version' => $this->pupil()['version'], 'confirm_enrollments' => true], ['student' => $this->student])))),
            // FR-ELV-01: an optional photo, added, replaced or removed; the previous image is deleted.
            ActionGroup::make([
                Action::make('photo')->label(fn () => __($this->pupil()['has_photo'] ? 'schooling.change_photo' : 'schooling.add_photo'))
                    ->visible(fn () => app(StudentPhotos::class)->enabled())
                    ->schema([
                        // No preview: the browser would draw it in a worker the Content Security Policy refuses.
                        FileUpload::make('photo')->label(__('schooling.photo'))->storeFiles(false)->required()->previewable(false)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(StudentPhotos::MAX_KB)->helperText(__('schooling.photo_help')),
                        Toggle::make('square')->label(__('schooling.photo_square'))->helperText(__('schooling.photo_square_help'))->default(true),
                    ])
                    ->action(fn (array $data) => $this->changed(SchoolScreen::changeFor('student.record.update', 'student', $this->student,
                        fn () => SchoolScreen::call('student.record.update', StudentPhotoController::class, 'store',
                            [...$data, 'version' => $this->pupil()['version']], ['student' => $this->student])))),
                Action::make('removePhoto')->label(__('schooling.remove_photo'))->color('danger')->requiresConfirmation()
                    ->modalDescription(__('schooling.remove_photo_help'))->visible(fn () => $this->pupil()['has_photo'])
                    ->action(fn () => $this->changed(SchoolScreen::changeFor('student.record.update', 'student', $this->student,
                        fn () => SchoolScreen::call('student.record.update', StudentPhotoController::class, 'destroy',
                            ['version' => $this->pupil()['version']], ['student' => $this->student])))),
            ])->label(__('schooling.photo'))->icon('heroicon-m-camera')->button()->color('gray')->visible(fn () => $this->editable()),
            Action::make('archive')->label(__('school.archive'))->color('danger')->requiresConfirmation()->modalDescription(__('schooling.archive_help'))
                ->visible(fn () => $this->pupil()['status'] === 'former' && !$this->pupil()['archived_at']
                    && $this->writableFor('student.record.archive', 'student', $this->student))
                ->schema([$this->reason()])
                ->action(fn (array $data) => $this->changed(SchoolScreen::changeFor('student.record.archive', 'student', $this->student,
                    fn () => SchoolScreen::call('student.record.archive', StudentController::class, 'archive',
                        [...$data, 'version' => $this->pupil()['version']], ['student' => $this->student])))),
        ];
    }

    /** The pupil's guardians (FR-ELV-02). */
    public function table(Table $table): Table
    {
        return $table->heading(__('schooling.guardians'))->paginated(false)
            ->records(fn () => collect(SchoolScreen::call('guardian.record.read', GuardianController::class, 'links', parameters: ['student' => $this->student])['data'])
                ->keyBy('id')->all())
            ->emptyStateHeading(__('schooling.no_guardian'))->emptyStateDescription(__('schooling.no_guardian_help'))
            ->columns([
                TextColumn::make('guardian')->label(__('school.name'))->state(fn (array $record) => $record['guardian'] ? $this->personName($record['guardian']) : ''),
                TextColumn::make('relationship')->label(__('schooling.relationship'))->formatStateUsing(fn (string $state) => __('schooling.relationships.'.$state)),
                TextColumn::make('phone')->label(__('schooling.phone'))->state(fn (array $record) => Formats::isolated($record['guardian']['phone'] ?? null)),
                IconColumn::make('is_primary_contact')->label(__('schooling.primary_contact'))->boolean(),
                IconColumn::make('is_payer')->label(__('schooling.payer'))->boolean(),
                IconColumn::make('emergency_contact')->label(__('schooling.emergency_contact'))->boolean(),
            ])->headerActions([
                Action::make('link')->label(__('schooling.link_guardian'))->visible(fn () => $this->linkable('guardian.record.create'))
                    ->schema([Select::make('guardian_id')->label(__('schooling.guardian'))->required()->searchable()
                        ->getSearchResultsUsing(fn (string $search) => $this->guardianOptions($search))
                        ->getOptionLabelUsing(fn ($value) => $this->guardianLabel($value)), ...$this->linkFields()])
                    ->action(fn (array $data) => SchoolScreen::change('guardian.record.create',
                        fn () => SchoolScreen::call('guardian.record.create', GuardianController::class, 'link', $data, ['student' => $this->student]))),
                // A new guardian and their link, together or not at all. The same guardian typed again (a
                // brother's father) is found first, and linked rather than recorded twice (FR-ELV-02 AC1).
                Action::make('createGuardian')->label(__('schooling.create_guardian'))->visible(fn () => $this->linkable('guardian.record.create'))
                    ->mountUsing(function (?Schema $schema = null) {
                        $this->sameGuardians = [];
                        $schema?->fill();
                    })
                    ->schema(fn () => [...$this->guardianFields(), ...$this->customFields('guardian'), ...$this->linkFields(),
                        Callout::make(__('schooling.guardian_duplicates_found'))->warning()->visible(fn () => $this->sameGuardians !== [])
                            ->description(fn () => Formats::list(array_map(fn (array $row) => $this->guardianLine($row), $this->sameGuardians))),
                        Radio::make('existing')->label(__('schooling.guardian_choice'))->live()
                            ->visible(fn () => $this->sameGuardians !== [])->required(fn () => $this->sameGuardians !== [])
                            ->options(fn () => [...collect($this->sameGuardians)->mapWithKeys(fn (array $row) => [$row['id'] =>
                                __('schooling.link_this_guardian', ['name' => $this->personName($row)])])->all(), 'new' => __('schooling.create_anyway')]),
                        Textarea::make('duplicate_reason')->label(__('schooling.guardian_duplicate_reason'))->helperText(__('schooling.duplicate_reason_help'))
                            ->visible(fn (Get $get) => $get('existing') === 'new')->required(fn (Get $get) => $get('existing') === 'new')->minLength(3)->maxLength(500),
                    ])
                    ->action(function (array $data) {
                        $link = array_intersect_key($data, array_flip(['relationship', 'is_primary_contact', 'is_payer', 'emergency_contact']));
                        $person = array_diff_key($data, $link, ['existing' => 1]);
                        $existing = $data['existing'] ?? null;
                        if ($existing === null) {
                            $this->sameGuardians = $this->guardianDuplicates($person);
                            if ($this->sameGuardians !== []) {
                                Notification::make()->title(__('schooling.guardian_duplicates_found'))->warning()->send();
                                throw new Halt();
                            }
                        }
                        SchoolScreen::change('guardian.record.create', function () use ($person, $link, $existing) {
                            $guardian = $existing !== null && $existing !== 'new' ? $existing
                                : SchoolScreen::call('guardian.record.create', GuardianController::class, 'store', $this->sentPerson($person, 'guardian'))['data']['id'];
                            return SchoolScreen::call('guardian.record.create', GuardianController::class, 'link', [...$link, 'guardian_id' => $guardian], ['student' => $this->student]);
                        });
                        $this->sameGuardians = [];
                    }),
            ])->recordActions([ActionGroup::make([
                Action::make('editLink')->label(__('schooling.edit_link'))->visible(fn () => $this->linkable('guardian.record.update'))
                    ->fillForm(fn (array $record) => $record)->schema([...$this->linkFields(), Hidden::make('version')->required()])
                    ->action(fn (array $record, array $data) => SchoolScreen::change('guardian.record.update',
                        fn () => SchoolScreen::call('guardian.record.update', GuardianController::class, 'updateLink', $data, ['student' => $this->student, 'link' => $record['id']]))),
                Action::make('editGuardian')->label(__('schooling.edit_guardian'))
                    ->visible(fn (array $record) => $record['guardian'] !== null && $this->writableFor('guardian.record.update', 'guardian', $record['guardian_id']))
                    ->fillForm(fn (array $record) => SchoolScreen::call('guardian.record.read', GuardianController::class, 'show', parameters: ['guardian' => $record['guardian_id']])['data'])
                    ->schema(fn () => [...$this->guardianFields(), ...$this->customFields('guardian'), Hidden::make('version')->required()])
                    ->action(fn (array $record, array $data) => SchoolScreen::changeFor('guardian.record.update', 'guardian', $record['guardian_id'],
                        fn () => SchoolScreen::call('guardian.record.update', GuardianController::class, 'update', $this->sentPerson($data, 'guardian'), ['guardian' => $record['guardian_id']]))),
                Action::make('unlink')->label(__('schooling.unlink'))->color('danger')->requiresConfirmation()->visible(fn () => $this->linkable('guardian.record.update'))
                    ->schema([$this->reason()])
                    ->action(fn (array $record, array $data) => SchoolScreen::change('guardian.record.update',
                        fn () => SchoolScreen::call('guardian.record.update', GuardianController::class, 'unlink', [...$data, 'version' => $record['version']],
                            ['student' => $this->student, 'link' => $record['id']]))),
            ])]);
    }

    private function editable(): bool
    {
        return $this->pupil()['status'] !== 'former' && !$this->pupil()['archived_at'] && $this->writableFor('student.record.update', 'student', $this->student);
    }

    /** A former pupil's guardians stay as they are; links are written by school-wide grants (increment 31). */
    private function linkable(string $permission): bool
    {
        return $this->pupil()['status'] !== 'former' && !$this->pupil()['archived_at'] && $this->writable($permission);
    }

    private function linkFields(): array
    {
        return [Select::make('relationship')->label(__('schooling.relationship'))->required()->options(__('schooling.relationships')),
            Toggle::make('is_primary_contact')->label(__('schooling.primary_contact'))->helperText(__('schooling.primary_contact_help')),
            Toggle::make('is_payer')->label(__('schooling.payer')), Toggle::make('emergency_contact')->label(__('schooling.emergency_contact'))];
    }

    /** @return array<string, string> the guardians matching the search, at most 50 */
    private function guardianOptions(string $search): array
    {
        return collect(SchoolScreen::call('guardian.record.read', GuardianController::class, 'index', ['q' => $search, 'per_page' => 50])['data'])
            ->mapWithKeys(fn ($guardian) => [$guardian['id'] => $this->personName($guardian).($guardian['phone'] ? ' · '.Formats::isolated($guardian['phone']) : '')])->all();
    }

    private function guardianLabel(?string $guardian): ?string
    {
        if (!$guardian) return null;
        $row = SchoolScreen::call('guardian.record.read', GuardianController::class, 'show', parameters: ['guardian' => $guardian])['data'];
        return $this->personName($row).($row['phone'] ? ' · '.Formats::isolated($row['phone']) : '');
    }

    /** The enrolments a block leaves as they are, counted whatever the reader may see of them (FR-ELV-03 AC1). */
    private function enrollmentCount(): int
    {
        return (int) DB::selectOne('SELECT school.student_enrollment_count(?) AS n', [$this->student])->n;
    }

    /** After a change, the sheet reads the pupil again. */
    private function changed(array $result): array
    {
        $this->pupilRow = null;
        $this->cacheSchema('identityInfolist', null);
        return $result;
    }
}
