<?php

namespace App\Filament\School\Pages;

use App\Filament\School\Pages\Concerns\EnrolsPupils;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\GroupMembershipController;
use App\Http\Controllers\ProgressionController;
use App\Http\Controllers\StudyGroupController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * FR-GRP-02: one group's members, from their first day until their last. A place is taken with
 * the group locked, so that two requests never both get the last one (BR-07, TST-07); a transfer
 * ends today's membership and opens the next one together, both kept.
 */
final class GroupMembers extends SchoolingPage
{
    use EnrolsPupils;

    protected static bool $isDiscovered = true;
    protected static bool $shouldRegisterNavigation = false;
    protected static array $permissions = ['group.group.read'];
    protected static ?string $module = 'enrollment_groups';
    protected static string|array $routeMiddleware = ['module:enrollment_groups', 'permission:group.group.read'];
    #[Locked] public string $group = '';
    private ?array $groupRow = null;
    private ?array $targetRows = null;
    private ?array $laterRows = null;

    public function mount(): void
    {
        parent::mount();
        $this->group = (string) request()->query('group', '');
        $this->studyGroup();
    }

    /** The group as the reader may see it; a group out of their reach is not found. */
    public function studyGroup(): array
    {
        abort_unless(Str::isUuid($this->group), 404);
        return $this->groupRow ??= SchoolScreen::call('group.group.read', StudyGroupController::class, 'show', parameters: ['group' => $this->group])['data'];
    }

    public function getTitle(): string
    {
        $group = $this->studyGroup();
        return $group['code'].' · '.Locales::label($group['labels']);
    }

    public function getSubheading(): ?string
    {
        $group = $this->studyGroup();
        return Formats::list(array_filter([__('schooling.group_types.'.$group['group_type']), $group['program_code'], $this->siteName($group['site_id']),
            __('schooling.places_taken', ['members' => Formats::number($group['members']), 'capacity' => Formats::number($group['capacity'])]),
            __('schooling.group_states.'.$group['status'])]));
    }

    public function table(Table $table): Table
    {
        return $table->records(fn (array $filters, int $page, int $recordsPerPage) => $this->apiPage('group.group.read', GroupMembershipController::class, 'index',
            array_filter(['include_history' => ($filters['history']['isActive'] ?? false) ?: null]), $page, $recordsPerPage, ['group' => $this->group]))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)
            ->emptyStateHeading(__('schooling.no_member'))
            ->columns([
                TextColumn::make('enrollment')->label(__('schooling.student'))
                    ->state(fn (array $record) => isset($record['enrollment']['matricule']) ? $record['enrollment']['matricule'].' · '.$this->personName($record['enrollment']) : '')
                    ->url(fn (array $record) => isset($record['enrollment']['matricule']) ? StudentSheet::getUrl(['student' => $record['enrollment']['student_id']]) : null),
                TextColumn::make('enrollment.status')->label(__('schooling.enrollment'))
                    ->state(fn (array $record) => isset($record['enrollment']['status']) ? __('schooling.enrollment_states.'.$record['enrollment']['status']) : ''),
                IconColumn::make('is_main')->label(__('schooling.main_group'))->boolean(),
                TextColumn::make('enrollment.decision')->label(__('schooling.progression'))
                    ->state(fn (array $record) => isset($record['enrollment']['decision']) ? __('schooling.decisions.'.$record['enrollment']['decision']) : ''),
                TextColumn::make('valid_from')->label(__('schooling.member_from'))->formatStateUsing(fn ($state) => Formats::date($state)),
                TextColumn::make('valid_to')->label(__('schooling.member_until'))->formatStateUsing(fn ($state) => Formats::date($state)),
            ])->filters([Filter::make('history')->label(__('schooling.include_history'))->toggle()])
            ->headerActions([
                Action::make('add')->label(__('schooling.add_member'))
                    ->visible(fn () => $this->studyGroup()['status'] === 'active' && $this->creatable('group.membership.manage', $this->studyGroup()['site_id']))
                    ->schema([Select::make('enrollment_id')->label(__('schooling.enrollment'))->required()->searchable()->helperText(__('schooling.add_member_help'))
                        ->getSearchResultsUsing(fn (string $search) => $this->assignable($search))
                        // The chosen enrolment, named again when the form is sent (without it, Filament refuses the choice).
                        ->getOptionLabelUsing(fn ($value) => $this->enrollmentLabel($value))])
                    ->action(fn (array $data) => $this->changed(SchoolScreen::changeAt('group.membership.manage', $this->studyGroup()['site_id'],
                        fn () => SchoolScreen::call('group.membership.manage', GroupMembershipController::class, 'store', $data, ['group' => $this->group])))),
                // FR-INS-05: the year's end for the class's validated pupils without a decision, at once (all or nothing).
                Action::make('progression')->label(__('schooling.progression_action'))->modalDescription(__('schooling.progression_help'))
                    ->visible(fn () => in_array($this->studyGroup()['group_type'], ['class', 'session'], true) && SchoolScreen::writable(null)
                        && app(\App\Support\Permissions::class)->can('enrollment.progression.decide') && $this->undecided() !== [])
                    ->schema(fn () => [
                        CheckboxList::make('enrollments')->label(__('schooling.students'))->required()->options($this->undecided())->bulkToggleable()->columns(2),
                        Radio::make('decision')->label(__('schooling.progression'))->required()->live()->options(__('schooling.decisions')),
                        Select::make('target_group_id')->label(__('schooling.target_class'))->helperText(__('schooling.target_class_help'))
                            ->visible(fn (Get $get) => in_array($get('decision'), ['promoted', 'repeated'], true))
                            ->required(fn (Get $get) => in_array($get('decision'), ['promoted', 'repeated'], true))->options(fn () => $this->laterClasses()),
                        DatePicker::make('due_date')->label(__('schooling.first_due_date'))->helperText(__('schooling.first_due_date_help'))
                            ->visible(fn (Get $get) => in_array($get('decision'), ['promoted', 'repeated'], true))
                            ->required(fn (Get $get) => in_array($get('decision'), ['promoted', 'repeated'], true)),
                        $this->reason(),
                    ])
                    ->action(function (array $data) {
                        $target = in_array($data['decision'], ['promoted', 'repeated'], true);
                        $this->changed(SchoolScreen::change(null, fn () => SchoolScreen::call('enrollment.progression.decide', ProgressionController::class, 'store',
                            ['decisions' => array_map(fn (string $enrollment) => ['enrollment_id' => $enrollment, 'decision' => $data['decision'], 'reason' => $data['reason'],
                                'target_group_id' => $target ? $data['target_group_id'] : null, 'due_date' => $target ? substr((string) $data['due_date'], 0, 10) : null],
                                array_values($data['enrollments']))])));
                    }),
            ])->recordActions([ActionGroup::make([
                Action::make('transfer')->label(__('schooling.transfer'))->modalDescription(__('schooling.transfer_help'))
                    ->visible(fn (array $record) => $record['valid_to'] === null && $this->targets() !== [])
                    ->schema(fn (array $record) => [Select::make('group_id')->label(__('schooling.target_group'))->required()->options($this->targets()), $this->reason()])
                    ->action(fn (array $record, array $data) => $this->changed(SchoolScreen::changeAt('group.membership.manage', $this->targetSite($data['group_id']),
                        fn () => SchoolScreen::call('group.membership.manage', GroupMembershipController::class, 'transfer', [...$data, 'version' => $record['version']], ['membership' => $record['id']])))),
                Action::make('end')->label(__('schooling.end_membership'))->color('danger')->requiresConfirmation()
                    ->visible(fn (array $record) => $record['valid_to'] === null && $this->writableFor('group.membership.manage', 'group_membership', $record['id']))
                    ->schema([$this->reason()])
                    ->action(fn (array $record, array $data) => $this->changed(SchoolScreen::changeFor('group.membership.manage', 'group_membership', $record['id'],
                        fn () => SchoolScreen::call('group.membership.manage', GroupMembershipController::class, 'end', [...$data, 'version' => $record['version']], ['membership' => $record['id']])))),
            ])]);
    }

    private function enrollmentLabel(?string $enrollment): ?string
    {
        if (!$enrollment) return null;
        $row = SchoolScreen::call('enrollment.enrollment.read', EnrollmentController::class, 'show', parameters: ['enrollment' => $enrollment])['data'];
        return ($row['student']['matricule'] ?? '').' · '.($row['student'] ? $this->personName($row['student']) : '').' · '.$row['terms_snapshot']['code'];
    }

    /** @return array<string, string> the year's enrolments that may join: pending or validated, of the programme for a class or a session */
    private function assignable(string $search): array
    {
        $group = $this->studyGroup();
        $filters = ['year_id' => $group['year_id'], 'q' => $search, 'per_page' => 50,
            ...(in_array($group['group_type'], ['class', 'session'], true) ? ['program_id' => $group['program_id']] : [])];
        return collect(['pending', 'validated'])->flatMap(fn ($status) => SchoolScreen::call('enrollment.enrollment.read', EnrollmentController::class, 'index', [...$filters, 'status' => $status])['data'])
            ->filter(fn ($enrollment) => $enrollment['student'] !== null)
            ->mapWithKeys(fn ($enrollment) => [$enrollment['id'] => $enrollment['student']['matricule'].' · '.$this->personName($enrollment['student'])
                .' · '.$enrollment['terms_snapshot']['code'].' · '.__('schooling.enrollment_states.'.$enrollment['status'])])->all();
    }

    /**
     * @return array<string, string> the open groups a member may move to: of the same year, and of the same programme for a
     *   class or a session, on a site where the writer places pupils (SQL 029)
     */
    private function targets(): array
    {
        $group = $this->studyGroup();
        return collect($this->targetGroups())
            ->filter(fn ($target) => $target['id'] !== $group['id'] && $this->creatable('group.membership.manage', $target['site_id']) && (!in_array($group['group_type'], ['class', 'session'], true)
                ? $target['group_type'] === $group['group_type'] : in_array($target['group_type'], ['class', 'session'], true) && $target['program_id'] === $group['program_id']))
            ->mapWithKeys(fn ($target) => [$target['id'] => $this->groupName($target)])->all();
    }

    /** The year's open groups, read once per request. */
    private function targetGroups(): array
    {
        return $this->targetRows ??= SchoolScreen::call('group.group.read', StudyGroupController::class, 'index',
            ['year_id' => $this->studyGroup()['year_id'], 'status' => 'active', 'per_page' => 100])['data'];
    }

    private function targetSite(string $group): ?string
    {
        return collect($this->targetGroups())->firstWhere('id', $group)['site_id'] ?? null;
    }

    /** @return array<string, string> the class's current members with a validated enrolment and no decision yet */
    private function undecided(): array
    {
        return collect(SchoolScreen::call('group.group.read', GroupMembershipController::class, 'index', ['per_page' => 100], ['group' => $this->group])['data'])
            ->filter(fn (array $member) => $member['is_main'] && ($member['enrollment']['status'] ?? null) === 'validated' && ($member['enrollment']['decision'] ?? null) === null
                && isset($member['enrollment']['matricule']))
            ->mapWithKeys(fn (array $member) => [$member['enrollment_id'] => $member['enrollment']['matricule'].' · '.$this->personName($member['enrollment'])])->all();
    }

    /** @return array<string, string> the classes and sessions of later years, open or in preparation, with their places */
    private function laterClasses(): array
    {
        $start = $this->years()[$this->studyGroup()['year_id']]['start_date'] ?? null;
        $later = collect($this->years())->filter(fn (array $year) => $start !== null && $year['start_date'] > $start)->keys()->all();
        return collect($this->laterRows ??= collect(['active', 'draft'])->flatMap(fn (string $status) => SchoolScreen::call('group.group.read', StudyGroupController::class, 'index',
                ['status' => $status, 'per_page' => 100])['data'])->all())
            ->filter(fn (array $group) => in_array($group['year_id'], $later, true) && in_array($group['group_type'], ['class', 'session'], true))
            ->mapWithKeys(fn (array $group) => [$group['id'] => ($this->years()[$group['year_id']]['code'] ?? '').' · '.$this->groupName($group)])->all();
    }

    /** After a change, the group's places are read again. */
    private function changed(array $result): array
    {
        $this->groupRow = null;
        return $result;
    }
}
