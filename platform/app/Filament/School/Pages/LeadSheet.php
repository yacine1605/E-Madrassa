<?php

namespace App\Filament\School\Pages;

use App\Filament\School\Pages\Concerns\EnrolsPupils;
use App\Filament\School\Pages\Concerns\FollowsLeads;
use App\Http\Controllers\GuardianController;
use App\Http\Controllers\LeadController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\Permissions;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * FR-CRM-01, FR-CRM-02: one prospect's sheet. Its contact, source, step and assignee; its
 * planned reminders; its children, each converted once into a pupil, their guardian and their
 * enrolment; the interactions with the family, which never change.
 */
final class LeadSheet extends SchoolingPage
{
    use EnrolsPupils;
    use FollowsLeads;

    protected static bool $isDiscovered = true;
    protected static bool $shouldRegisterNavigation = false;
    protected static array $permissions = ['crm.lead.read'];
    protected static ?string $module = 'students_crm';
    protected static string|array $routeMiddleware = ['module:students_crm', 'permission:crm.lead.read'];
    protected string $view = 'filament.school.lead-sheet';
    #[Locked] public string $lead = '';
    private ?array $leadRow = null;
    private ?array $reminderRows = null;

    public function mount(): void
    {
        parent::mount();
        $this->lead = (string) request()->query('lead', '');
        $this->prospect();
    }

    /** The prospect as the reader may see it; one out of their reach is not found. */
    public function prospect(): array
    {
        abort_unless(Str::isUuid($this->lead), 404);
        return $this->leadRow ??= SchoolScreen::call('crm.lead.read', LeadController::class, 'show', parameters: ['lead' => $this->lead])['data'];
    }

    public function getTitle(): string { return $this->contactName($this->prospect()); }

    public function getSubheading(): ?string
    {
        $lead = $this->prospect();
        return Formats::list(array_filter([__('admission.leads'), $this->leadOptionName('status', $lead['status']), $this->siteName($lead['site_id']),
            $lead['closed_at'] ? __('admission.closed_on', ['date' => Formats::date($lead['closed_at'])]) : null]));
    }

    /** The contact and its follow-up, then the reminders still planned. */
    public function leadInfolist(Schema $schema): Schema
    {
        $rows = $this->identity();
        $reminders = $this->plannedReminders();
        return $schema->components([
            Section::make(__('admission.contact'))->columns(['default' => 1, 'sm' => 2])
                ->schema(array_map(fn (string $label, string $value, int $index) => TextEntry::make('identity_'.$index)->label($label)->state($value),
                    array_keys($rows), array_values($rows), array_keys(array_values($rows)))),
            Section::make(__('admission.reminders'))->description(__('admission.reminder_help'))->schema([RepeatableEntry::make('reminders')->hiddenLabel()
                ->columns(['default' => 1, 'sm' => 3])->placeholder(__('admission.no_reminder'))
                ->state(array_map(fn (array $reminder) => ['due_at' => Formats::dateTime($reminder['due_at']), 'body' => Formats::isolated($reminder['body']),
                    'author' => Formats::isolated($reminder['author'])], $reminders))
                ->schema([TextEntry::make('due_at')->label(__('admission.due_at')), TextEntry::make('body')->label(__('admission.reminder_body')),
                    TextEntry::make('author')->label(__('admission.author'))])]),
        ]);
    }

    /** FR-CRM-02 AC2: what was said with the family, newest first; the pupil's sheet shows it too. */
    public function historyInfolist(Schema $schema): Schema
    {
        $interactions = SchoolScreen::call('crm.lead.read', LeadController::class, 'interactions', ['per_page' => 100], ['lead' => $this->lead])['data'];
        return $schema->components([Section::make(__('admission.interactions'))->description(__('admission.no_interaction_help'))
            ->schema([RepeatableEntry::make('interactions')->hiddenLabel()->columns(['default' => 1, 'sm' => 4])->placeholder(__('admission.no_interaction'))
                ->state(array_map(fn (array $row) => ['occurred_at' => Formats::dateTime($row['occurred_at']), 'kind' => __('admission.interaction_kinds.'.$row['kind']),
                    'author' => Formats::isolated($row['author']), 'body' => Formats::isolated($row['body'])], $interactions))
                ->schema([TextEntry::make('occurred_at')->label(__('admission.occurred_at')), TextEntry::make('kind')->label(__('admission.interaction_kind')),
                    TextEntry::make('author')->label(__('admission.author')), TextEntry::make('body')->label(__('admission.body'))])])]);
    }

    /** @return array<string, string> label and value */
    private function identity(): array
    {
        $lead = $this->prospect();
        $rows = [__('schooling.phone') => $lead['phone'], __('school.email') => $lead['email'],
            __('admission.relationship') => $lead['relationship'] ? __('admission.relationships.'.$lead['relationship']) : null,
            __('admission.source') => $this->leadOptionName('source', $lead['source']), __('admission.status') => $this->leadOptionName('status', $lead['status']),
            __('admission.lost_reason') => $lead['lost_reason'], __('admission.assigned_to') => $lead['assigned_name'], __('schooling.site') => $this->siteName($lead['site_id'])];
        foreach ($this->customDefinitions('lead', true) as $field) {
            $value = $lead['custom'][$field['key']] ?? null;
            if ($value !== null && $value !== '') $rows[Locales::label($field['labels']) ?? $field['key']] = $this->customValue($field, $value);
        }
        return array_map(fn ($value) => Formats::isolated($value), array_filter($rows, fn ($value) => $value !== null && $value !== ''));
    }

    private function plannedReminders(): array
    {
        return $this->reminderRows ??= SchoolScreen::call('crm.lead.read', LeadController::class, 'reminders', ['planned' => true, 'per_page' => 100],
            ['lead' => $this->lead])['data'];
    }

    protected function getHeaderActions(): array
    {
        $site = fn () => $this->prospect()['site_id'];
        return [
            Action::make('interaction')->label(__('admission.add_interaction'))->visible(fn () => $this->followable())
                ->schema([Select::make('kind')->label(__('admission.interaction_kind'))->required()->default('call')->options(__('admission.interaction_kinds')),
                    DateTimePicker::make('occurred_at')->label(__('admission.occurred_at'))->helperText(__('admission.occurred_help'))->maxDate(now()->addMinutes(5))->seconds(false),
                    Textarea::make('body')->label(__('admission.body'))->required()->maxLength(2000)])
                ->action(fn (array $data) => $this->changed(SchoolScreen::changeAt('crm.lead.update', $site(), fn () => SchoolScreen::call('crm.lead.update',
                    LeadController::class, 'addInteraction', ['kind' => $data['kind'], 'occurred_at' => $data['occurred_at'] ?: null, 'body' => $data['body']],
                    ['lead' => $this->lead])))),
            ActionGroup::make([
                Action::make('reminder')->label(__('admission.add_reminder'))
                    ->schema([DateTimePicker::make('due_at')->label(__('admission.due_at'))->required()->minDate(now()->startOfDay())->seconds(false),
                        Textarea::make('body')->label(__('admission.reminder_body'))->required()->minLength(3)->maxLength(500)->helperText(__('admission.reminder_help'))])
                    ->action(fn (array $data) => $this->changed(SchoolScreen::changeAt('crm.lead.update', $site(), fn () => SchoolScreen::call('crm.lead.update',
                        LeadController::class, 'addReminder', $data, ['lead' => $this->lead])))),
                $this->reminderAction('completeReminder', 'complete_reminder'),
                $this->reminderAction('cancelReminder', 'cancel_reminder'),
            ])->label(__('admission.reminders'))->icon('heroicon-m-bell')->button()->color('gray')->visible(fn () => $this->followable() && !$this->prospect()['closed_at']),
            Action::make('status')->label(__('admission.change_status'))->visible(fn () => $this->creatable('crm.lead.update', $this->prospect()['site_id']))
                ->schema(fn () => [
                    Select::make('status')->label(__('admission.status'))->required()->live()->options($this->stepOptions($this->prospect()['status'])),
                    Textarea::make('lost_reason')->label(__('admission.lost_reason'))->helperText(__('admission.lost_reason_help'))->minLength(3)->maxLength(500)
                        ->visible(fn (Get $get) => $get('status') === 'lost')->required(fn (Get $get) => $get('status') === 'lost'),
                    Textarea::make('reason')->label(__('admission.reopen_reason'))->helperText(__('admission.reopen_help'))->minLength(3)->maxLength(500)
                        ->visible(fn () => (bool) $this->prospect()['closed_at'])->required(fn () => (bool) $this->prospect()['closed_at']),
                ])
                ->action(fn (array $data) => $this->changed(SchoolScreen::changeAt('crm.lead.update', $site(), fn () => SchoolScreen::call('crm.lead.update',
                    LeadController::class, 'status', [...$data, 'version' => $this->prospect()['version']], ['lead' => $this->lead])))),
            Action::make('edit')->label(__('school.edit'))->visible(fn () => $this->followable() && !$this->prospect()['closed_at'])
                ->fillForm(fn () => $this->prospect())
                ->schema(fn () => [...$this->contactFields(),
                    Select::make('source')->label(__('admission.source'))->required()->options(fn () => $this->leadOptions('source', [$this->prospect()['source']])),
                    Select::make('assigned_to')->label(__('admission.assigned_to'))->helperText(__('admission.assigned_help'))
                        ->options(fn () => $this->assignees($this->prospect()['site_id'])),
                    ...$this->customFields('lead'), Hidden::make('version')->required()])
                ->action(fn (array $data) => $this->changed(SchoolScreen::changeAt('crm.lead.update', $site(), fn () => SchoolScreen::call('crm.lead.update',
                    LeadController::class, 'update', $this->sentPerson($data, 'lead'), ['lead' => $this->lead])))),
        ];
    }

    /** A planned reminder, done or cancelled. */
    private function reminderAction(string $method, string $label): Action
    {
        return Action::make($method)->label(__('admission.'.$label))->visible(fn () => $this->plannedReminders() !== [])
            ->schema(fn () => [Select::make('reminder')->label(__('admission.reminder'))->required()
                ->options(collect($this->plannedReminders())->mapWithKeys(fn (array $reminder) => [$reminder['id'] => __('admission.reminder_line',
                    ['date' => Formats::dateTime($reminder['due_at']), 'body' => Str::limit($reminder['body'], 60)])])->all())])
            ->action(function (array $data) use ($method) {
                $reminder = collect($this->plannedReminders())->firstWhere('id', $data['reminder']);
                abort_unless($reminder, 404);
                $this->changed(SchoolScreen::changeAt('crm.lead.update', $this->prospect()['site_id'], fn () => SchoolScreen::call('crm.lead.update',
                    LeadController::class, $method, ['version' => $reminder['version']], ['reminder' => $reminder['id']])));
            });
    }

    /** The prospect's children; each one converted once into a pupil (FR-CRM-02). */
    public function table(Table $table): Table
    {
        return $table->heading(__('admission.children'))->paginated(false)
            ->records(fn () => collect($this->prospect()['children'])->keyBy('id')->all())
            ->columns([
                TextColumn::make('name')->label(__('school.name'))->state(fn (array $record) => $this->childName($record)),
                TextColumn::make('birth_date')->label(__('admission.birth_date'))->formatStateUsing(fn ($state) => Formats::date($state)),
                TextColumn::make('level_id')->label(__('admission.level'))->formatStateUsing(fn (?string $state) => $this->nodeName($state) ?? ''),
                TextColumn::make('student_id')->label(__('school.status'))->badge()
                    ->state(fn (array $record) => $record['student_id'] ? __('admission.converted') : __('admission.not_converted'))
                    ->color(fn (array $record) => $record['student_id'] ? 'success' : 'warning')
                    ->description(fn (array $record) => $record['converted_at'] ? Formats::date($record['converted_at']) : null),
            ])->headerActions([
                Action::make('addChild')->label(__('admission.add_child'))->visible(fn () => $this->followable() && !$this->prospect()['closed_at'])
                    ->schema(fn () => $this->childFields())
                    ->action(fn (array $data) => $this->changed(SchoolScreen::changeAt('crm.lead.update', $this->prospect()['site_id'], fn () => SchoolScreen::call('crm.lead.update',
                        LeadController::class, 'addChild', $this->sentChild($data), ['lead' => $this->lead])))),
            ])->recordActions([ActionGroup::make([
                Action::make('openStudent')->label(__('admission.open_student'))->visible(fn (array $record) => $record['student_id'] !== null
                    && app(Permissions::class)->can('student.record.read'))->url(fn (array $record) => StudentSheet::getUrl(['student' => $record['student_id']])),
                $this->convertAction(),
                Action::make('editChild')->label(__('admission.edit_child'))
                    ->visible(fn (array $record) => $record['student_id'] === null && $this->followable() && !$this->prospect()['closed_at'])
                    ->fillForm(fn (array $record) => $record)->schema(fn () => [...$this->childFields(), Hidden::make('version')->required()])
                    ->action(fn (array $record, array $data) => $this->changed(SchoolScreen::changeAt('crm.lead.update', $this->prospect()['site_id'],
                        fn () => SchoolScreen::call('crm.lead.update', LeadController::class, 'updateChild', $this->sentChild($data), ['child' => $record['id']])))),
                Action::make('removeChild')->label(__('admission.remove_child'))->color('danger')->requiresConfirmation()->modalDescription(__('admission.remove_child_help'))
                    ->visible(fn (array $record) => $record['student_id'] === null && $this->followable() && !$this->prospect()['closed_at'])
                    ->schema([$this->reason()])
                    ->action(fn (array $record, array $data) => $this->changed(SchoolScreen::changeAt('crm.lead.update', $this->prospect()['site_id'],
                        fn () => SchoolScreen::call('crm.lead.update', LeadController::class, 'archiveChild', [...$data, 'version' => $record['version']], ['child' => $record['id']])))),
            ])]);
    }

    /**
     * FR-CRM-02: the child becomes a pupil, with the contact as their guardian (or one recorded
     * already, or none for an adult) and an enrolment, from what the prospect says. The request's
     * key is made when the form opens: the same form sent twice makes one pupil (AC1).
     */
    private function convertAction(): Action
    {
        return Action::make('convert')->label(__('admission.convert'))->color('primary')->modalDescription(__('admission.convert_help'))
            ->visible(fn (array $record) => $record['student_id'] === null && $this->prospect()['status'] !== 'lost'
                && $this->creatable('crm.lead.convert', $this->prospect()['site_id']) && $this->creatable('student.record.create', $this->prospect()['site_id'])
                && $this->creatable('enrollment.enrollment.create'))
            ->fillForm(fn (array $record) => $this->conversionDefaults($record))
            ->schema(fn () => [
                Section::make(__('admission.pupil'))->statePath('student')->columns(2)->schema([...$this->studentFields(), ...$this->customFields('student'),
                    Textarea::make('duplicate_reason')->label(__('admission.student_duplicate_reason'))->helperText(__('admission.duplicate_reason_help'))
                        ->minLength(3)->maxLength(500)->columnSpanFull()]),
                Radio::make('guardian_mode')->label(__('admission.guardian_mode'))->required()->live()->helperText(__('admission.guardian_help'))
                    ->options(fn () => collect(__('admission.guardian_modes'))->except($this->creatable('guardian.record.create', $this->prospect()['site_id']) ? [] : ['contact', 'existing'])->all()),
                Section::make(__('admission.guardian'))->statePath('guardian')->columns(2)->visible(fn (Get $get) => $get('guardian_mode') === 'contact')
                    ->schema([...$this->guardianFields(), ...$this->customFields('guardian'),
                        Textarea::make('duplicate_reason')->label(__('admission.guardian_duplicate_reason'))->helperText(__('admission.duplicate_reason_help'))
                            ->minLength(3)->maxLength(500)->columnSpanFull()]),
                Select::make('guardian_id')->label(__('admission.existing_guardian'))->searchable()
                    ->visible(fn (Get $get) => $get('guardian_mode') === 'existing')->required(fn (Get $get) => $get('guardian_mode') === 'existing')
                    ->getSearchResultsUsing(fn (string $search) => collect(SchoolScreen::call('guardian.record.read', GuardianController::class, 'index', ['q' => $search, 'per_page' => 50])['data'])
                        ->mapWithKeys(fn ($guardian) => [$guardian['id'] => $this->personName($guardian).($guardian['phone'] ? ' · '.Formats::isolated($guardian['phone']) : '')])->all()),
                Select::make('relationship')->label(__('schooling.relationship'))->options(__('schooling.relationships'))
                    ->visible(fn (Get $get) => in_array($get('guardian_mode'), ['contact', 'existing'], true))->required(fn (Get $get) => in_array($get('guardian_mode'), ['contact', 'existing'], true)),
                Toggle::make('is_primary_contact')->label(__('schooling.primary_contact'))->default(true)->visible(fn (Get $get) => in_array($get('guardian_mode'), ['contact', 'existing'], true)),
                Toggle::make('is_payer')->label(__('schooling.payer'))->default(true)->visible(fn (Get $get) => in_array($get('guardian_mode'), ['contact', 'existing'], true)),
                Select::make('program_id')->label(__('admission.program'))->required()->searchable()->helperText(__('admission.program_help'))
                    ->getSearchResultsUsing(fn (string $search) => $this->activePrograms($search))->getOptionLabelUsing(fn ($value) => $this->programName($value)),
                Hidden::make('idempotency_key'),
            ])
            ->action(function (array $record, array $data) {
                $mode = $data['guardian_mode'];
                $sent = ['idempotency_key' => $data['idempotency_key'], 'program_id' => $data['program_id'], 'student' => $this->sentStudent($data['student'] ?? [])];
                if ($mode === 'contact') $sent['guardian'] = $this->sentPerson($data['guardian'] ?? [], 'guardian');
                if ($mode === 'existing') $sent['guardian_id'] = $data['guardian_id'];
                if ($mode !== 'none') $sent += ['relationship' => $data['relationship'], 'is_primary_contact' => (bool) ($data['is_primary_contact'] ?? true),
                    'is_payer' => (bool) ($data['is_payer'] ?? true)];
                foreach (['student', 'guardian'] as $person) if (isset($sent[$person]) && ($sent[$person]['duplicate_reason'] ?? null) === null) unset($sent[$person]['duplicate_reason']);
                $result = SchoolScreen::changeAt('crm.lead.convert', $this->prospect()['site_id'], fn () => SchoolScreen::call('crm.lead.convert',
                    LeadController::class, 'convert', $sent, ['child' => $record['id']]), fn () => Notification::make()->title(__('school.saved'))->success());
                $this->changed($result);
                if (app(Permissions::class)->can('student.record.read')) $this->redirect(StudentSheet::getUrl(['student' => $result['data']['student_id']]));
            });
    }

    /** What the prospect already says: the child's names in their alphabet, the contact as guardian unless an adult enrols. */
    private function conversionDefaults(array $child): array
    {
        $lead = $this->prospect();
        $names = fn (?string $last, ?string $first) => preg_match('/\p{Arabic}/u', $last.$first)
            ? ['last_name_ar' => $last, 'first_name_ar' => $first] : ['last_name_lat' => $last, 'first_name_lat' => $first];
        $self = $lead['relationship'] === 'self';
        return [
            'student' => [...$names($child['last_name'] ?? $lead['last_name'], $child['first_name']), 'birth_date' => $child['birth_date'],
                ...($self ? ['phone' => $lead['phone'], 'email' => $lead['email']] : [])],
            'guardian_mode' => $self ? 'none' : 'contact',
            'guardian' => [...$names($lead['last_name'], $lead['first_name']), 'phone' => $lead['phone'], 'email' => $lead['email']],
            'relationship' => in_array($lead['relationship'], ['father', 'mother', 'guardian', 'other'], true) ? $lead['relationship'] : null,
            'is_primary_contact' => true, 'is_payer' => true,
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    /** Follow the prospect: interactions, reminders, children; on its site. */
    private function followable(): bool { return $this->creatable('crm.lead.update', $this->prospect()['site_id']); }

    /** After a change, the sheet reads the prospect again. */
    private function changed(array $result): array
    {
        $this->leadRow = null;
        $this->reminderRows = null;
        $this->cacheSchema('leadInfolist', null);
        $this->cacheSchema('historyInfolist', null);
        return $result;
    }
}
