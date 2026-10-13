<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\ProgramController;
use App\Http\Controllers\ProgramSubjectController;
use App\Http\Controllers\SubjectController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * FR-PRG-02: the subjects or modules a programme teaches, with their volume: total hours, weekly
 * hours, sessions. A changed volume is a new version, the previous ones kept; a subject ends, or
 * is removed while nothing uses it. Hours and sessions that imply sessions more than 10 % away
 * from their length are pointed out, never refused.
 */
final class ProgramSubjects extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static bool $shouldRegisterNavigation = false;
    protected static array $permissions = ['program.program.read'];
    protected static ?string $module = 'enrollment_groups';
    protected static string|array $routeMiddleware = ['module:enrollment_groups', 'permission:program.program.read'];
    #[Locked] public string $program = '';
    private ?array $programRow = null;

    public function mount(): void
    {
        parent::mount();
        $this->program = (string) request()->query('program', '');
        $this->programme();
    }

    /** The programme as the reader may see it; one out of their reach is not found. */
    public function programme(): array
    {
        abort_unless(Str::isUuid($this->program), 404);
        return $this->programRow ??= SchoolScreen::call('program.program.read', ProgramController::class, 'show', parameters: ['program' => $this->program])['data'];
    }

    public function getTitle(): string
    {
        $program = $this->programme();
        return $program['code'].' · '.Locales::label($program['labels']);
    }

    public function getSubheading(): ?string
    {
        $program = $this->programme();
        return Formats::list(array_filter([__('schooling.program_subjects'), $this->years()[$program['year_id']]['code'] ?? null,
            $this->nodeName($program['node_id']), __('schooling.program_states.'.$program['status'])]));
    }

    public function table(Table $table): Table
    {
        return $table->records(fn (array $filters, int $page, int $recordsPerPage) => $this->apiPage('program.program.read', ProgramSubjectController::class, 'index',
            array_filter(['include_ended' => ($filters['ended']['isActive'] ?? false) ?: null]), $page, $recordsPerPage, ['program' => $this->program]))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)
            ->emptyStateHeading(__('schooling.no_program_subject'))->emptyStateDescription(__('schooling.no_program_subject_help'))
            ->columns([
                TextColumn::make('subject')->label(__('schooling.program_subject'))
                    ->state(fn (array $record) => $record['subject']['code'].' · '.Locales::label($record['subject']['labels'])),
                TextColumn::make('total_hours')->label(__('schooling.total_hours'))->formatStateUsing(fn ($state) => $this->hours($state)),
                TextColumn::make('weekly_hours')->label(__('schooling.weekly_hours'))->formatStateUsing(fn ($state) => $this->hours($state)),
                TextColumn::make('session_count')->label(__('schooling.session_count'))->formatStateUsing(fn ($state) => $state === null ? null : Formats::number((int) $state)),
                TextColumn::make('session_duration_minutes')->label(__('schooling.session_minutes'))
                    ->formatStateUsing(fn ($state) => __('schooling.minutes', ['minutes' => Formats::number((int) $state)])),
                // The average the hours and sessions imply, pointed out when it is far from the length of a session.
                TextColumn::make('implicit_minutes')->label(__('schooling.implicit_minutes'))
                    ->formatStateUsing(fn ($state) => $state === null ? null : __('schooling.minutes', ['minutes' => $this->decimal($state)]))
                    ->color(fn (array $record) => $record['duration_gap'] ? 'warning' : null)
                    ->tooltip(fn (array $record) => $record['duration_gap'] ? $this->gap($record) : null),
                TextColumn::make('coefficient')->label(__('schooling.coefficient'))->formatStateUsing(fn ($state) => $this->decimal($state)),
                TextColumn::make('valid_from')->label(__('schooling.taught_from'))->formatStateUsing(fn ($state) => Formats::date($state)),
                TextColumn::make('valid_to')->label(__('schooling.taught_until'))->formatStateUsing(fn ($state) => Formats::date($state)),
            ])->filters([Filter::make('ended')->label(__('schooling.include_ended'))->toggle()])
            ->headerActions([
                Action::make('add')->label(__('schooling.add_program_subject'))->visible(fn () => $this->changeable())
                    ->schema([
                        Select::make('subject_id')->label(__('schooling.program_subject'))->required()->searchable()->options(fn () => $this->subjects()),
                        ...$this->volumeFields(),
                        DatePicker::make('valid_from')->label(__('schooling.taught_from'))->helperText(__('schooling.valid_from_help')),
                    ])
                    ->action(fn (array $data) => SchoolScreen::changeFor('program.subject.manage', 'program', $this->program,
                        fn () => SchoolScreen::call('program.subject.manage', ProgramSubjectController::class, 'store', $this->sent($data), ['program' => $this->program]),
                        fn (array $result) => $this->saved($result['data']))),
            ])->recordActions([ActionGroup::make([
                Action::make('volume')->label(__('schooling.edit_volume'))->modalDescription(__('schooling.edit_volume_help'))
                    ->visible(fn (array $record) => $record['valid_to'] === null && $this->changeable())
                    ->fillForm(fn (array $record) => $record)->schema([...$this->volumeFields(), Hidden::make('version')->required()])
                    ->action(fn (array $record, array $data) => SchoolScreen::changeFor('program.subject.manage', 'program', $this->program,
                        fn () => SchoolScreen::call('program.subject.manage', ProgramSubjectController::class, 'update', $this->sent($data), ['line' => $record['id']]),
                        fn (array $result) => $this->saved($result['data']))),
                Action::make('volumes')->label(__('schooling.volumes'))->modalHeading(__('schooling.volumes'))->modalSubmitAction(false)
                    ->schema(fn (array $record) => $this->volumes($record)),
                Action::make('end')->label(__('schooling.end_subject'))->modalDescription(__('schooling.end_subject_help'))->color('warning')
                    ->visible(fn (array $record) => $record['valid_to'] === null && $this->changeable())
                    ->schema([DatePicker::make('valid_to')->label(__('schooling.end_on'))->required()->default(now()->toDateString())])
                    ->action(fn (array $record, array $data) => SchoolScreen::changeFor('program.subject.manage', 'program', $this->program,
                        fn () => SchoolScreen::call('program.subject.manage', ProgramSubjectController::class, 'end',
                            ['valid_to' => substr((string) $data['valid_to'], 0, 10), 'version' => $record['version']], ['line' => $record['id']]))),
                Action::make('remove')->label(__('schooling.remove_subject'))->modalDescription(__('schooling.remove_subject_help'))->color('danger')
                    ->requiresConfirmation()->visible(fn () => $this->changeable())
                    ->action(fn (array $record) => SchoolScreen::changeFor('program.subject.manage', 'program', $this->program,
                        fn () => SchoolScreen::call('program.subject.manage', ProgramSubjectController::class, 'destroy', ['version' => $record['version']], ['line' => $record['id']]))),
            ])]);
    }

    /** Whoever may change this programme's subjects, while its year is open. */
    private function changeable(): bool
    {
        return !$this->yearClosed($this->programme()['year_id']) && $this->writableFor('program.subject.manage', 'program', $this->program);
    }

    private function volumeFields(): array
    {
        $hours = fn (TextInput $field) => $field->numeric()->minValue(0.01)->rule('decimal:0,2')->helperText(__('schooling.hours_help'));
        return [
            $hours(TextInput::make('total_hours')->label(__('schooling.total_hours'))->maxValue(9999.99)
                ->requiredWithoutAll(['weekly_hours', 'session_count'])->validationMessages(['required_without_all' => __('schooling.volume_help')])),
            $hours(TextInput::make('weekly_hours')->label(__('schooling.weekly_hours'))->maxValue(168)),
            TextInput::make('session_count')->label(__('schooling.session_count'))->numeric()->integer()->minValue(1)->maxValue(10000),
            TextInput::make('session_duration_minutes')->label(__('schooling.session_minutes'))->numeric()->integer()->minValue(15)->maxValue(1440)
                ->helperText(fn () => __('schooling.session_minutes_help', ['minutes' => Formats::number($this->schoolMinutes())])),
            TextInput::make('coefficient')->label(__('schooling.coefficient'))->numeric()->minValue(0.01)->maxValue(999.99)->rule('decimal:0,2'),
        ];
    }

    /** @return array<string, string> the catalogue's subjects still in use */
    private function subjects(): array
    {
        return collect(SchoolScreen::call('referential.node.read', SubjectController::class, 'index')['data'])->whereNull('archived_at')
            ->mapWithKeys(fn ($subject) => [$subject['id'] => $subject['code'].' · '.Locales::label($subject['labels'])])->all();
    }

    /** FR-PRG-02 AC1: each volume the line has had, newest first, from the day it was set. */
    private function volumes(array $record): array
    {
        $versions = SchoolScreen::call('program.program.read', ProgramSubjectController::class, 'volumes', ['per_page' => 100], ['line' => $record['id']])['data'];
        return [RepeatableEntry::make('versions')->hiddenLabel()->columns(5)
            ->state(array_map(fn ($row) => ['version' => Formats::number($row['version']), 'since' => Formats::dateTime($row['created_at']),
                'total' => $this->hours($row['total_hours']), 'weekly' => $this->hours($row['weekly_hours']),
                'sessions' => $row['session_count'] === null ? null : Formats::number($row['session_count']).' × '
                    .__('schooling.minutes', ['minutes' => Formats::number($row['session_duration_minutes'])])], $versions))
            ->schema([TextEntry::make('version')->label(__('schooling.volume_version')), TextEntry::make('since')->label(__('schooling.since')),
                TextEntry::make('total')->label(__('schooling.total_hours')), TextEntry::make('weekly')->label(__('schooling.weekly_hours')),
                TextEntry::make('sessions')->label(__('schooling.session_count'))])];
    }

    /** Saved; with the implied length of a session when it is far from the one given (non-blocking). */
    private function saved(array $line): Notification
    {
        return $line['duration_gap'] ? Notification::make()->title(__('school.saved'))->body($this->gap($line))->warning()
            : Notification::make()->title(__('school.saved'))->success();
    }

    private function gap(array $line): string
    {
        return __('schooling.duration_gap', ['implicit' => $this->decimal($line['implicit_minutes']),
            'minutes' => Formats::number($line['session_duration_minutes'])]);
    }

    private function schoolMinutes(): int
    {
        return (int) DB::selectOne("SELECT school.setting_value('planning.session_minutes') #>> '{}' AS minutes")->minutes;
    }

    /** « 36 h », « 1,5 h »: hours as decimals (minutes over 60), without needless zeros. */
    private function hours(int|float|string|null $hours): ?string
    {
        return $hours === null ? null : __('schooling.hours', ['hours' => $this->decimal($hours)]);
    }

    private function decimal(int|float|string|null $value): ?string
    {
        if ($value === null) return null;
        $value = (float) $value;
        return Formats::number($value, floor($value) == $value ? 0 : (floor($value * 10) == $value * 10 ? 1 : 2));
    }

    /** As the form gives it: empty is none; a day, without its time. */
    private function sent(array $data): array
    {
        foreach (['total_hours', 'weekly_hours', 'session_count', 'session_duration_minutes', 'coefficient', 'valid_from'] as $field) {
            if (array_key_exists($field, $data) && ($data[$field] === '' || $data[$field] === null)) $data[$field] = null;
        }
        foreach (['session_count', 'session_duration_minutes'] as $field) if (isset($data[$field])) $data[$field] = (int) $data[$field];
        foreach (['total_hours', 'weekly_hours', 'coefficient'] as $field) if (isset($data[$field])) $data[$field] = (string) $data[$field];
        if (isset($data['valid_from'])) $data['valid_from'] = substr((string) $data['valid_from'], 0, 10);
        else unset($data['valid_from']);
        return $data;
    }
}
