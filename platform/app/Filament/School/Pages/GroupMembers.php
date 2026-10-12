<?php

namespace App\Filament\School\Pages;

use App\Filament\School\Pages\Concerns\EnrolsPupils;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\GroupMembershipController;
use App\Http\Controllers\StudyGroupController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
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
                TextColumn::make('valid_from')->label(__('schooling.member_from'))->formatStateUsing(fn ($state) => Formats::date($state)),
                TextColumn::make('valid_to')->label(__('schooling.member_until'))->formatStateUsing(fn ($state) => Formats::date($state)),
            ])->filters([Filter::make('history')->label(__('schooling.include_history'))->toggle()])
            ->headerActions([
                Action::make('add')->label(__('schooling.add_member'))->visible(fn () => $this->studyGroup()['status'] === 'active' && $this->writable('group.membership.manage'))
                    ->schema([Select::make('enrollment_id')->label(__('schooling.enrollment'))->required()->searchable()->helperText(__('schooling.add_member_help'))
                        ->getSearchResultsUsing(fn (string $search) => $this->assignable($search))])
                    ->action(fn (array $data) => $this->changed(SchoolScreen::change('group.membership.manage',
                        fn () => SchoolScreen::call('group.membership.manage', GroupMembershipController::class, 'store', $data, ['group' => $this->group])))),
            ])->recordActions([ActionGroup::make([
                Action::make('transfer')->label(__('schooling.transfer'))->modalDescription(__('schooling.transfer_help'))
                    ->visible(fn (array $record) => $record['valid_to'] === null && $this->writable('group.membership.manage'))
                    ->schema(fn (array $record) => [Select::make('group_id')->label(__('schooling.target_group'))->required()->options($this->targets()), $this->reason()])
                    ->action(fn (array $record, array $data) => $this->changed(SchoolScreen::change('group.membership.manage',
                        fn () => SchoolScreen::call('group.membership.manage', GroupMembershipController::class, 'transfer', [...$data, 'version' => $record['version']], ['membership' => $record['id']])))),
                Action::make('end')->label(__('schooling.end_membership'))->color('danger')->requiresConfirmation()
                    ->visible(fn (array $record) => $record['valid_to'] === null && $this->writableFor('group.membership.manage', 'group_membership', $record['id']))
                    ->schema([$this->reason()])
                    ->action(fn (array $record, array $data) => $this->changed(SchoolScreen::changeFor('group.membership.manage', 'group_membership', $record['id'],
                        fn () => SchoolScreen::call('group.membership.manage', GroupMembershipController::class, 'end', [...$data, 'version' => $record['version']], ['membership' => $record['id']])))),
            ])]);
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

    /** @return array<string, string> the open groups a member may move to: of the same year, and of the same programme for a class or a session */
    private function targets(): array
    {
        $group = $this->studyGroup();
        return collect(SchoolScreen::call('group.group.read', StudyGroupController::class, 'index', ['year_id' => $group['year_id'], 'status' => 'active', 'per_page' => 100])['data'])
            ->filter(fn ($target) => $target['id'] !== $group['id'] && (!in_array($group['group_type'], ['class', 'session'], true)
                ? $target['group_type'] === $group['group_type'] : in_array($target['group_type'], ['class', 'session'], true) && $target['program_id'] === $group['program_id']))
            ->mapWithKeys(fn ($target) => [$target['id'] => $this->groupName($target)])->all();
    }

    /** After a change, the group's places are read again. */
    private function changed(array $result): array
    {
        $this->groupRow = null;
        return $result;
    }
}
