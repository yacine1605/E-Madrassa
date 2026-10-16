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
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-INS-01 to FR-INS-03: the enrolments, at the price and terms of their day (BR-11), their
 * teaching state changed by hand with a reason (BR-12), and their groups. A cancelled enrolment
 * is frozen and gives its places back.
 */
final class Enrollments extends SchoolingPage
{
    use EnrolsPupils;

    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 7;
    protected static array $permissions = ['enrollment.enrollment.read'];
    protected static ?string $module = 'enrollment_groups';
    protected static string|array $routeMiddleware = ['module:enrollment_groups', 'permission:enrollment.enrollment.read'];

    public static function getNavigationLabel(): string { return __('schooling.enrollments'); }
    public function getTitle(): string { return __('schooling.enrollments'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (?string $search, array $filters, int $page, int $recordsPerPage) => $this->apiPage('enrollment.enrollment.read',
            EnrollmentController::class, 'index', array_filter(['q' => $search, 'status' => $filters['status']['value'] ?? null,
                'year_id' => $filters['year_id']['value'] ?? null, 'student_id' => $filters['student']['student_id'] ?? null]), $page, $recordsPerPage))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->columns([
                TextColumn::make('student')->label(__('schooling.student'))->searchable()
                    ->state(fn (array $record) => $record['student'] ? $record['student']['matricule'].' · '.$this->personName($record['student']) : '')
                    ->url(fn (array $record) => $record['student'] ? StudentSheet::getUrl(['student' => $record['student_id']]) : null),
                TextColumn::make('program')->label(__('schooling.program'))
                    ->state(fn (array $record) => $record['terms_snapshot']['code'].' · '.Locales::label($record['terms_snapshot']['labels'])),
                TextColumn::make('year_id')->label(__('reference.year'))->formatStateUsing(fn (string $state) => $this->years()[$state]['code'] ?? ''),
                TextColumn::make('main_group')->label(__('schooling.main_group'))->state(fn (array $record) => $record['main_group']['code'] ?? '')
                    ->url(fn (array $record) => isset($record['main_group']) ? GroupMembers::getUrl(['group' => $record['main_group']['id']]) : null),
                TextColumn::make('agreed_price')->label(__('schooling.agreed_price'))->formatStateUsing(fn ($state) => $this->money($state)),
                TextColumn::make('status')->label(__('school.status'))->badge()->formatStateUsing(fn (string $state) => __('schooling.enrollment_states.'.$state)),
                // FR-INS-04: whether it opens the pupil's courses, computed when read.
                IconColumn::make('access_granted')->label(__('schooling.access_granted'))->boolean()->tooltip(__('schooling.access_granted_help')),
                TextColumn::make('created_at')->label(__('schooling.enrolled_on'))->formatStateUsing(fn ($state) => Formats::date($state)),
            ])->filters([
                SelectFilter::make('status')->label(__('school.status'))->options(__('schooling.enrollment_states')),
                SelectFilter::make('year_id')->label(__('reference.year'))->options(fn () => collect($this->years())->pluck('code', 'id')->all()),
                Filter::make('student')->schema([Select::make('student_id')->label(__('schooling.student'))->searchable()
                    ->getSearchResultsUsing(fn (string $search) => $this->studentOptions($search))->getOptionLabelUsing(fn ($value) => $this->studentLabel($value))]),
            ])->headerActions([$this->enrolAction()])
            ->recordActions([ActionGroup::make([
                Action::make('assign')->label(__('schooling.assign_group'))
                    ->visible(fn (array $record) => in_array($record['status'], ['pending', 'validated'], true) && $this->creatable('group.membership.manage'))
                    ->schema(fn (array $record) => [Select::make('group_id')->label(__('schooling.group'))->required()->options($this->yearGroups($record))])
                    ->action(fn (array $record, array $data) => SchoolScreen::changeAt('group.membership.manage', null,
                        fn () => SchoolScreen::call('group.membership.manage', GroupMembershipController::class, 'store', ['enrollment_id' => $record['id']], ['group' => $data['group_id']]))),
                $this->stateAction('validate', 'validateEnrollment', 'enrollment.enrollment.validate', ['pending', 'suspended'], 'success'),
                $this->stateAction('suspend', 'suspend', 'enrollment.enrollment.suspend', ['validated'], 'warning'),
                $this->stateAction('cancel', 'cancel', 'enrollment.enrollment.cancel', ['pending', 'validated', 'suspended'], 'danger')
                    ->modalDescription(__('schooling.cancel_help')),
                $this->transferAction(),
                // FR-FIN-01: what it owes and when.
                Action::make('finance')->label(__('finance.open_finance'))
                    ->visible(fn () => DB::selectOne('SELECT school.module_enabled(?) AS on', ['student_finance'])->on && collect(['finance.payment.read', 'finance.discount.grant',
                        'finance.schedule.manage', 'finance.discount.approve'])->contains(fn (string $p) => app(\App\Support\Permissions::class)->can($p)))
                    ->url(fn (array $record) => EnrollmentFinance::getUrl(['enrollment' => $record['id']])),
                Action::make('history')->label(__('schooling.history'))->modalHeading(__('schooling.history'))->modalSubmitAction(false)
                    ->schema(fn (array $record) => $this->history($record)),
            ])]);
    }

    /** BR-12: one transition, with its reason, by whoever may make it on this enrolment. */
    private function stateAction(string $name, string $method, string $permission, array $from, string $color): Action
    {
        return Action::make($name)->label(__('schooling.enrollment_actions.'.$name))->color($color)->requiresConfirmation()
            ->visible(fn (array $record) => in_array($record['status'], $from, true) && $this->writableFor($permission, 'enrollment', $record['id']))
            ->schema([$this->reason()])
            ->action(fn (array $record, array $data) => SchoolScreen::changeFor($permission, 'enrollment', $record['id'],
                fn () => SchoolScreen::call($permission, EnrollmentController::class, $method, [...$data, 'version' => $record['version']], ['enrollment' => $record['id']])));
    }

    /**
     * FR-INS-03: to another active programme, with its class at once if the reader may place pupils.
     * The form's key is made when it opens: sent twice, it makes one transfer (TST-08).
     */
    private function transferAction(): Action
    {
        return Action::make('transfer')->label(__('schooling.enrollment_actions.transfer'))->modalDescription(__('schooling.transfer_help'))
            ->visible(fn (array $record) => in_array($record['status'], ['pending', 'validated', 'suspended'], true)
                && $this->creatable('enrollment.enrollment.create') && $this->writableFor('enrollment.enrollment.cancel', 'enrollment', $record['id']))
            ->schema(fn (array $record) => [
                Select::make('program_id')->label(__('schooling.transfer_program'))->required()->searchable()->live()->helperText(__('schooling.active_programs_only'))
                    ->getSearchResultsUsing(fn (string $search) => array_diff_key($this->activePrograms($search), [$record['program_id'] => true]))
                    ->getOptionLabelUsing(fn ($value) => $this->programName($value))->afterStateUpdated(fn (Set $set) => $set('group_id', null)),
                Select::make('group_id')->label(__('schooling.main_group'))->helperText(__('schooling.enrol_group_help'))
                    ->visible(fn (Get $get) => filled($get('program_id')) && $this->creatable('group.membership.manage'))
                    ->options(fn (Get $get) => $this->mainGroups($get('program_id'))),
                $this->reason(),
                Hidden::make('idempotency_key')->default(fn () => (string) Str::uuid()),
            ])
            ->action(fn (array $record, array $data) => SchoolScreen::changeFor('enrollment.enrollment.cancel', 'enrollment', $record['id'], function () use ($record, $data) {
                $target = SchoolScreen::call('enrollment.enrollment.cancel', EnrollmentController::class, 'transfer', ['program_id' => $data['program_id'],
                    'idempotency_key' => $data['idempotency_key'], 'reason' => $data['reason'], 'version' => $record['version']], ['enrollment' => $record['id']]);
                // The same form sent again finds the new enrolment already in this group.
                if (!empty($data['group_id']) && ($target['data']['main_group']['id'] ?? null) !== $data['group_id']) {
                    SchoolScreen::call('group.membership.manage', GroupMembershipController::class, 'store', ['enrollment_id' => $target['data']['id']], ['group' => $data['group_id']]);
                }
                return $target;
            }, fn () => Notification::make()->title(__('schooling.transferred'))->success()));
    }

    /** @return array<string, string> the open groups of the enrolment's year: its programme's classes, and any support or option group */
    private function yearGroups(array $record): array
    {
        return collect(SchoolScreen::call('group.group.read', StudyGroupController::class, 'index', ['year_id' => $record['year_id'], 'status' => 'active', 'per_page' => 100])['data'])
            ->filter(fn ($group) => !in_array($group['group_type'], ['class', 'session'], true) || $group['program_id'] === $record['program_id'])
            ->filter(fn ($group) => $group['id'] !== ($record['main_group']['id'] ?? null))
            ->mapWithKeys(fn ($group) => [$group['id'] => $this->groupName($group).' · '.__('schooling.group_types.'.$group['group_type'])])->all();
    }

    /** FR-INS-02, FR-GRP-02 AC3: the states with their reasons, and the groups with their dates. */
    private function history(array $record): array
    {
        $states = SchoolScreen::call('enrollment.enrollment.read', EnrollmentController::class, 'history', ['per_page' => 100], ['enrollment' => $record['id']])['data'];
        $groups = SchoolScreen::call('enrollment.enrollment.read', EnrollmentController::class, 'groups', ['per_page' => 100], ['enrollment' => $record['id']])['data'];
        $link = fn (?array $enrollment) => $enrollment ? $enrollment['code'].' · '.Locales::label($enrollment['labels']) : null;
        return [
            // FR-INS-03 AC2: the enrolment a transfer came from, or went to.
            Section::make(__('schooling.transfer'))->compact()->columns(2)->visible($record['transfer_source'] !== null || $record['transfer_target'] !== null)
                ->schema([TextEntry::make('transferred_from')->label(__('schooling.transferred_from'))->state($link($record['transfer_source']))->visible($record['transfer_source'] !== null),
                    TextEntry::make('transferred_to')->label(__('schooling.transferred_to'))->state($link($record['transfer_target']))->visible($record['transfer_target'] !== null)]),
            Section::make(__('schooling.status_history'))->compact()->schema([RepeatableEntry::make('states')->hiddenLabel()->columns(3)
                ->state(array_map(fn ($row) => ['when' => Formats::dateTime($row['changed_at']), 'reason' => $row['reason'],
                    'change' => ($row['from_status'] ? __('schooling.enrollment_states.'.$row['from_status']).' → ' : '').__('schooling.enrollment_states.'.$row['to_status'])], $states))
                ->schema([TextEntry::make('when')->label(__('schooling.changed_on')), TextEntry::make('change')->label(__('school.status')),
                    TextEntry::make('reason')->label(__('admin.reason'))])]),
            Section::make(__('schooling.group_history'))->compact()->schema([RepeatableEntry::make('groups')->hiddenLabel()->columns(3)->placeholder(__('schooling.no_group'))
                ->state(array_map(fn ($row) => ['group' => $row['group_info'] ? $row['group_info']['code'].' · '.Locales::label($row['group_info']['labels']) : null,
                    'from' => Formats::date($row['valid_from']), 'to' => $row['valid_to'] ? Formats::date($row['valid_to']) : null], $groups))
                ->schema([TextEntry::make('group')->label(__('schooling.group')), TextEntry::make('from')->label(__('schooling.member_from')),
                    TextEntry::make('to')->label(__('schooling.member_until'))])]),
        ];
    }
}
