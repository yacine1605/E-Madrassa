<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\StaffController;
use App\Support\CurrentSchool;
use App\Support\Formats;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * FR-STF-01: the staff's records, teachers and the others, each on a site, with or without an
 * account (P7). A departure is recorded with its day, never deleted; only a member who left is
 * archived.
 */
final class Staff extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static ?string $section = 'staff';
    // After the schooling's pages: its section follows theirs in the menu.
    protected static ?int $navigationSort = 8;
    protected static array $permissions = ['staff.profile.read'];
    protected static ?string $module = 'staff';
    protected static string|array $routeMiddleware = ['module:staff', 'permission:staff.profile.read'];
    // From one state to the others, as the database allows them.
    private const NEXT = ['active' => ['on_leave', 'left'], 'on_leave' => ['active', 'left'], 'left' => ['active']];

    public static function getNavigationLabel(): string { return __('staff.staff'); }
    public function getTitle(): string { return __('staff.staff'); }

    public function table(Table $table): Table
    {
        return $table->records(fn (?string $search, array $filters, int $page, int $recordsPerPage) => $this->apiPage('staff.profile.read',
            StaffController::class, 'index', array_filter(['q' => $search, 'status' => $filters['status']['value'] ?? null,
                'staff_type' => $filters['staff_type']['value'] ?? null, 'site_id' => $filters['site_id']['value'] ?? null,
                'include_archived' => ($filters['archived']['isActive'] ?? false) ?: null, ...$this->qualifiedFilter($filters['qualified'] ?? [])]),
                $page, $recordsPerPage))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)->columns([
                TextColumn::make('staff_number')->label(__('staff.number'))->searchable(),
                // The account the member signs in with, under their name.
                TextColumn::make('name')->label(__('school.name'))->state(fn (array $record) => $this->personName($record))
                    ->description(fn (array $record) => $record['account'] ? $record['account']['name'] : __('staff.no_account')),
                TextColumn::make('staff_type')->label(__('staff.type'))->formatStateUsing(fn (string $state) => __('staff.types.'.$state)),
                TextColumn::make('phone')->label(__('schooling.phone'))->formatStateUsing(fn (?string $state) => Formats::isolated($state)),
                TextColumn::make('site_id')->label(__('schooling.site'))->formatStateUsing(fn (string $state) => $this->siteName($state) ?? ''),
                TextColumn::make('status')->label(__('school.status'))->badge()
                    ->state(fn (array $record) => $record['archived_at'] ? 'archived' : $record['status'])
                    ->formatStateUsing(fn (string $state, array $record) => $state === 'left'
                        ? __('staff.left_since', ['date' => Formats::date($record['left_on'])]) : __('staff.states.'.$state)),
            ])->filters([
                SelectFilter::make('status')->label(__('school.status'))->options(collect(__('staff.states'))->except('archived')->all()),
                SelectFilter::make('staff_type')->label(__('staff.type'))->options(__('staff.types')),
                SelectFilter::make('site_id')->label(__('schooling.site'))->options(fn () => collect($this->sites())->mapWithKeys(fn ($site) => [$site['id'] => $this->siteName($site['id'])])->all()),
                Filter::make('archived')->label(__('schooling.include_archived'))->toggle(),
                // FR-STF-02 AC2: the teachers qualified for a subject at a level, a cycle's qualification covering its levels.
                Filter::make('qualified')->schema([
                    Select::make('subject_id')->label(__('staff.qualified_for'))->searchable()->options(fn () => $this->subjectOptions()),
                    Select::make('node_id')->label(__('staff.node'))->searchable()->options(fn () => collect($this->nodes())->whereNull('archived_at')
                        ->mapWithKeys(fn (array $node) => [$node['id'] => $this->nodeName($node['id'])])->all()),
                    DatePicker::make('on')->label(__('staff.qualified_on')),
                ]),
            ])->headerActions([
                Action::make('create')->label(__('staff.create'))->visible(fn () => $this->creatable('staff.profile.create'))
                    ->schema(fn () => [...$this->staffFields(), $this->siteField('staff.profile.create')->helperText(__('staff.site_help')),
                        TextInput::make('staff_number')->label(__('staff.number'))->regex('/^[A-Za-z0-9_-]{1,30}$/')->helperText(__('staff.number_help')),
                        ...$this->customFields('staff')])
                    ->action(fn (array $data) => SchoolScreen::changeAt('staff.profile.create', $data['site_id'] ?? null,
                        fn () => SchoolScreen::call('staff.profile.create', StaffController::class, 'store', $this->sentStaff($data)),
                        fn (array $result) => Notification::make()->title(__('staff.created', ['number' => $result['data']['staff_number']]))->success())),
            ])->recordActions([ActionGroup::make([
                Action::make('qualifications')->label(__('staff.qualifications'))
                    ->visible(fn (array $record) => in_array($record['staff_type'], ['teacher_internal', 'teacher_external'], true))
                    ->url(fn (array $record) => StaffQualifications::getUrl(['staff' => $record['id']])),
                Action::make('edit')->label(__('school.edit'))->visible(fn (array $record) => $this->changeable($record))
                    ->fillForm(fn (array $record) => $record)
                    ->schema(fn (array $record) => [...$this->staffFields(),
                        Select::make('site_id')->label(__('schooling.site'))->required()->helperText(__('staff.site_help'))
                            ->options(fn () => [$record['site_id'] => $this->siteName($record['site_id'])] + $this->creationSites('staff.profile.update')),
                        ...$this->customFields('staff'), Hidden::make('version')->required()])
                    ->action(function (array $record, array $data) {
                        // The site is sent when it changes: moving a record needs the grant on the new one (SQL 029, 031).
                        if (($data['site_id'] ?? null) === $record['site_id']) unset($data['site_id']);
                        SchoolScreen::changeFor('staff.profile.update', 'staff', $record['id'], fn () => SchoolScreen::call('staff.profile.update',
                            StaffController::class, 'update', $this->sentStaff($data), ['staff' => $record['id']]));
                    }),
                Action::make('status')->label(__('schooling.change_status'))->visible(fn (array $record) => $this->changeable($record))
                    ->schema(fn (array $record) => [
                        Select::make('status')->label(__('school.status'))->required()->live()
                            ->options(collect(__('staff.states'))->only(self::NEXT[$record['status']])->all()),
                        DatePicker::make('left_on')->label(__('staff.left_on'))->helperText(__('staff.left_on_help'))->maxDate(now())
                            ->minDate($record['hire_date'])->visible(fn (Get $get) => $get('status') === 'left'),
                        $this->reason()])
                    ->action(fn (array $record, array $data) => SchoolScreen::changeFor('staff.profile.update', 'staff', $record['id'],
                        fn () => SchoolScreen::call('staff.profile.update', StaffController::class, 'status',
                            [...$data, 'left_on' => empty($data['left_on']) ? null : substr((string) $data['left_on'], 0, 10), 'version' => $record['version']],
                            ['staff' => $record['id']]))),
                Action::make('account')->label(__('staff.link_account'))->visible(fn (array $record) => $this->changeable($record))
                    ->schema([Select::make('user_id')->label(__('staff.account'))->required()->searchable()->helperText(__('staff.account_help'))
                        ->getSearchResultsUsing(fn (string $search) => $this->accountOptions($search))
                        ->getOptionLabelUsing(fn ($value) => $this->accountLabel((int) $value))])
                    ->action(fn (array $record, array $data) => $this->link($record, (int) $data['user_id'])),
                Action::make('unlink')->label(__('staff.unlink_account'))->color('gray')->requiresConfirmation()
                    ->modalDescription(__('staff.unlink_account_help'))
                    ->visible(fn (array $record) => $record['user_id'] !== null && $this->changeable($record))
                    ->action(fn (array $record) => $this->link($record, null)),
                Action::make('archive')->label(__('school.archive'))->color('danger')->requiresConfirmation()->modalDescription(__('staff.archive_help'))
                    ->visible(fn (array $record) => $record['status'] === 'left' && $this->changeable($record))
                    ->schema([$this->reason()])
                    ->action(fn (array $record, array $data) => SchoolScreen::changeFor('staff.profile.update', 'staff', $record['id'],
                        fn () => SchoolScreen::call('staff.profile.update', StaffController::class, 'archive',
                            [...$data, 'version' => $record['version']], ['staff' => $record['id']]))),
            ])]);
    }

    /** A record still in use, within the reader's grant to change it (their sites, or the whole school). */
    private function changeable(array $record): bool
    {
        return !$record['archived_at'] && $this->writableFor('staff.profile.update', 'staff', $record['id']);
    }

    private function staffFields(): array
    {
        return [...$this->nameFields(),
            Select::make('staff_type')->label(__('staff.type'))->required()->options(__('staff.types')),
            TextInput::make('phone')->label(__('schooling.phone'))->tel()->maxLength(40),
            TextInput::make('email')->label(__('school.email'))->email()->maxLength(200),
            DatePicker::make('hire_date')->label(__('staff.hire_date'))];
    }

    private function link(array $record, ?int $user): void
    {
        SchoolScreen::changeFor('staff.profile.update', 'staff', $record['id'], fn () => SchoolScreen::call('staff.profile.update',
            StaffController::class, 'account', ['user_id' => $user, 'version' => $record['version']], ['staff' => $record['id']]));
    }

    /** @return array<int, string> the school's accounts free for a record, matching the search, at most 50 */
    private function accountOptions(string $search): array
    {
        return collect(SchoolScreen::call('staff.profile.update', StaffController::class, 'accounts', ['q' => $search, 'per_page' => 50])['data'])
            ->mapWithKeys(fn (array $account) => [$account['id'] => $account['name'].($account['email'] ? ' · '.$account['email'] : '')])->all();
    }

    /** The qualification filter as the API takes it: a subject and a node together, a day if given. */
    private function qualifiedFilter(array $filter): array
    {
        if (empty($filter['subject_id']) || empty($filter['node_id'])) return [];
        return ['subject_id' => $filter['subject_id'], 'node_id' => $filter['node_id'],
            'on' => empty($filter['on']) ? null : substr((string) $filter['on'], 0, 10)];
    }


    private function accountLabel(int $user): ?string
    {
        $account = DB::selectOne('SELECT u.name, u.email FROM school.membership m JOIN public.users u ON u.id = m.user_id WHERE m.tenant_id = ? AND m.user_id = ?',
            [app(CurrentSchool::class)->id(), $user]);
        return $account ? $account->name.($account->email ? ' · '.$account->email : '') : null;
    }

    private function sentStaff(array $data): array
    {
        $data = $this->sentPerson($data, 'staff');
        if (array_key_exists('hire_date', $data)) $data['hire_date'] = $data['hire_date'] ? substr((string) $data['hire_date'], 0, 10) : null;
        if (($data['staff_number'] ?? null) === null) unset($data['staff_number']);
        unset($data['account'], $data['user_id']);
        return $data;
    }
}
