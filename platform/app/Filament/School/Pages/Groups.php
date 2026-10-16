<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\ProgramController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StudyGroupController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\Permissions;
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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * FR-GRP-01: classes, support and option groups, training sessions, each on a site for a year,
 * with its places. A class or a session is the main group of its programme's pupils; fewer
 * places than members is refused (AC2), and a closed group keeps its composition. Its lead teacher,
 * one of the staff's teachers, reads it with its pupils through a personal grant (SQL 033).
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
                TextColumn::make('lead_teacher')->label(__('schooling.lead_teacher'))->visible(fn () => $this->readsStaff())
                    ->state(fn (array $record) => $record['lead_teacher'] ? $this->personName($record['lead_teacher'])
                        : ($record['lead_teacher_id'] ? __('schooling.lead_teacher_hidden') : null)),
                TextColumn::make('site_id')->label(__('schooling.site'))->formatStateUsing(fn (string $state) => $this->siteName($state) ?? ''),
                TextColumn::make('main_room')->label(__('schooling.main_room'))->toggleable()
                    ->state(fn (array $record) => $record['main_room'] ? $this->roomLabel($record['main_room']) : null),
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
                Action::make('create')->label(__('schooling.create_group'))->visible(fn () => $this->creatable('group.group.manage') && $this->openYears() !== [])
                    ->schema([
                        Select::make('year_id')->label(__('reference.year'))->required()->live()->options(fn () => $this->openYears())
                            ->afterStateUpdated(fn (Set $set) => $set('program_id', null)),
                        Select::make('group_type')->label(__('schooling.group_type'))->required()->live()->default('class')->options(__('schooling.group_types'))
                            ->helperText(__('schooling.group_type_help')),
                        Select::make('program_id')->label(__('schooling.program'))->searchable()
                            ->required(fn (Get $get) => in_array($get('group_type'), ['class', 'session'], true))
                            ->getSearchResultsUsing(fn (string $search, Get $get) => $this->programOptions($get('year_id'), $search))
                            ->getOptionLabelUsing(fn ($value) => $this->programLabel($value)),
                        $this->siteField('group.group.manage')->helperText(null)->live(), $this->leadTeacherField(),
                        $this->codeField(), ...$this->labelFields(), $this->capacityField(),
                        $this->mainRoomField(fn (Get $get) => $get('site_id')),
                        DatePicker::make('valid_from')->label(__('school.valid_from'))->required()->default(now()),
                        DatePicker::make('valid_to')->label(__('school.valid_until'))->afterOrEqual('valid_from'),
                        Select::make('status')->label(__('school.status'))->required()->default('draft')
                            ->options(collect(__('schooling.group_states'))->only(['draft', 'active'])->all()),
                    ])
                    ->action(function (array $data) {
                        if (!in_array($data['group_type'], ['class', 'session'], true) && empty($data['program_id'])) unset($data['program_id']);
                        SchoolScreen::changeAt('group.group.manage', $data['site_id'], fn () => SchoolScreen::call('group.group.manage', StudyGroupController::class, 'store', $this->sent($data)));
                    }),
            ])->recordUrl(fn (array $record) => GroupMembers::getUrl(['group' => $record['id']]))->recordActions([
                Action::make('members')->label(__('schooling.members'))->url(fn (array $record) => GroupMembers::getUrl(['group' => $record['id']])),
                Action::make('edit')->label(__('school.edit'))
                    ->visible(fn (array $record) => $record['status'] !== 'closed' && !$this->yearClosed($record['year_id'])
                        && $this->writableFor('group.group.manage', 'study_group', $record['id']))
                    ->fillForm(fn (array $record) => $record)
                    ->schema(fn (array $record) => [...$this->labelFields(), $this->capacityField()->helperText(__('schooling.capacity_help')),
                        $this->leadTeacherField(), $this->mainRoomField(fn () => $record['site_id'], $record['main_room_id']),
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

    /** FR-GRP-01: optional, among the teachers whose records the writer reads, still with the school. */
    private function leadTeacherField(): Select
    {
        return Select::make('lead_teacher_id')->label(__('schooling.lead_teacher'))->searchable()->helperText(__('schooling.lead_teacher_help'))
            ->visible(fn () => $this->readsStaff())
            ->getSearchResultsUsing(fn (string $search) => $this->teacherOptions($search))
            ->getOptionLabelUsing(fn ($value) => $this->teacherLabel($value));
    }

    /**
     * FR-GRP-01, FR-SLL-02: optional, among the rooms of the group's site in use and available, with
     * their places; the one it has stays offered. The database refuses one smaller than the group.
     */
    private function mainRoomField(callable $site, ?string $current = null): Select
    {
        return Select::make('main_room_id')->label(__('schooling.main_room'))->helperText(__('schooling.main_room_help'))
            ->options(function (Get $get) use ($site, $current) {
                $siteId = $site($get);
                if (!$siteId) return [];
                return collect(SchoolScreen::call('referential.node.read', RoomController::class, 'index', ['site_id' => $siteId, 'per_page' => 100,
                    'include_archived' => $current !== null])['data'])
                    ->filter(fn (array $room) => $room['id'] === $current || ($room['status'] === 'active' && !$room['archived_at']))
                    ->mapWithKeys(fn (array $room) => [$room['id'] => $this->roomLabel($room)])->all();
            });
    }

    /** « A101 · Salle 101 · 30 places » */
    private function roomLabel(array $room): string
    {
        return implode(' · ', array_filter([$room['code'], Locales::label($room['labels']), __('schooling.room_places', ['places' => Formats::number($room['capacity'])])]));
    }

    private function readsStaff(): bool { return app(Permissions::class)->can('staff.profile.read'); }

    /** @return array<string, string> the teachers still with the school matching the search, at most 50 */
    private function teacherOptions(string $search): array
    {
        return collect(SchoolScreen::call('staff.profile.read', StaffController::class, 'index', ['q' => $search, 'per_page' => 100])['data'])
            ->filter(fn (array $staff) => in_array($staff['staff_type'], ['teacher_internal', 'teacher_external'], true) && $staff['status'] !== 'left')
            ->take(50)->mapWithKeys(fn (array $staff) => [$staff['id'] => $staff['staff_number'].' · '.$this->personName($staff)])->all();
    }

    /** A teacher of another site may lead a group of the writer's: named without their record then. */
    private function teacherLabel(?string $staff): ?string
    {
        if (!$staff) return null;
        try {
            $row = SchoolScreen::call('staff.profile.read', StaffController::class, 'show', parameters: ['staff' => $staff])['data'];
        } catch (NotFoundHttpException) {
            return __('schooling.lead_teacher_hidden');
        }
        return $row['staff_number'].' · '.$this->personName($row);
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
