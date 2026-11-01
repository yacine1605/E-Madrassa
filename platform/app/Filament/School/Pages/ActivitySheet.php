<?php

namespace App\Filament\School\Pages;

use App\Filament\School\Pages\Concerns\WritesActivities;
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\HomeworkSubmissionController;
use App\Http\Controllers\QuizAttemptController;
use App\Http\Controllers\ResultController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\Permissions;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * FR-LMS-02 to FR-LMS-04, an activity's sheet (`?activity=`), for the teacher of its group's subject
 * or the pedagogy manager: its settings and questions, what stops its publication, its versions.
 * Edited, published (a new frozen version each time), archived, or deleted while never published.
 * Then its pupils: their work, state, points, decisions and what is published; a homework marked
 * with its feedback, a quiz's open answers corrected, a zero for a missing work or an excused
 * absence decided with its reason, the results and the key published, a deadline extended for one
 * pupil, a link that does not open flagged; a quiz re-corrected by the manager with its reason.
 */
final class ActivitySheet extends SchoolingPage
{
    use WritesActivities;

    protected static bool $isDiscovered = true;
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $section = 'pedagogy';
    protected static array $permissions = ['lms.activity.manage'];
    protected static ?string $module = 'lms';
    protected static string|array $routeMiddleware = ['module:lms', 'permission:lms.activity.manage'];
    protected string $view = 'filament.school.activity-sheet';
    #[Locked] public string $activity = '';
    private ?array $sheetRow = null;
    private ?array $pupilRows = null;

    public function mount(): void
    {
        parent::mount();
        $this->activity = (string) request()->query('activity', '');
        $this->sheet();
    }

    /** The activity as its teacher sees it; one of another school, or of a group they do not teach, is not found. */
    public function sheet(): array
    {
        abort_unless(Str::isUuid($this->activity), 404);
        return $this->sheetRow ??= SchoolScreen::call(static::$permissions, ActivityController::class, 'show', parameters: ['activity' => $this->activity])['data'];
    }

    public function getTitle(): string { return $this->sheet()['title']; }

    public function getSubheading(): ?string
    {
        $activity = $this->sheet();
        return implode(' · ', [__('pedagogy.kinds.'.$activity['kind']), $activity['group_code'], $activity['subject_code'].' '.Locales::label($activity['subject_labels'] ?? [])]);
    }

    private function quiz(): bool { return $this->sheet()['kind'] === 'quiz'; }
    private function published(): bool { return $this->sheet()['published_version'] !== null; }
    private function archived(): bool { return $this->sheet()['archived_at'] !== null; }

    /** What stops its publication, then its settings. */
    public function detailsInfolist(Schema $schema): Schema
    {
        $a = $this->sheet();
        $quiz = $this->quiz();
        return $schema->components([
            Callout::make(__('pedagogy.problems'))->warning()->visible($a['problems'] !== [] && !$this->archived())
                ->description(Formats::list(array_unique(array_column($a['problems'], 'message')))),
            Section::make(__('pedagogy.settings'))->columns(['default' => 2, 'sm' => 4])->schema([
                TextEntry::make('state')->label(__('school.status'))->badge()->state(__('pedagogy.activity_states.'.$a['state'])),
                TextEntry::make('published_version')->label(__('pedagogy.version'))->state($a['published_version'] === null ? __('pedagogy.never_published')
                    : Formats::number((int) $a['published_version'])),
                TextEntry::make('opens_at')->label(__('pedagogy.opens_at'))->state(Formats::dateTime($a['opens_at']) ?: __('pedagogy.at_publication')),
                TextEntry::make('deadline_at')->label(__('pedagogy.deadline_at'))->state(Formats::dateTime($a['deadline_at'])),
                TextEntry::make('max_points')->label(__('pedagogy.max_points'))->state(Formats::number((float) $a['max_points'], 2)),
                TextEntry::make('coefficient')->label(__('pedagogy.coefficient'))->state(Formats::number((float) $a['coefficient'], 2)),
                TextEntry::make('duration')->label(__('pedagogy.duration_minutes'))->visible($quiz)
                    ->state(__('pedagogy.minutes', ['minutes' => Formats::number((int) $a['duration_minutes'])])),
                TextEntry::make('attempts')->label(__('pedagogy.max_attempts'))->visible($quiz)
                    ->state(Formats::number((int) $a['max_attempts']).' · '.__('pedagogy.selections.'.$a['selection'])),
                TextEntry::make('late')->label(__('pedagogy.late_policy'))->visible(!$quiz)->state(__('pedagogy.late_policies.'.$a['late_policy'])
                    .($a['late_until'] ? ' · '.Formats::dateTime($a['late_until']) : '')),
                TextEntry::make('results_visibility')->label(__('pedagogy.results_visibility'))->state(__('pedagogy.visibilities.'.$a['results_visibility'])),
                TextEntry::make('key_visibility')->label(__('pedagogy.key_visibility'))->visible($quiz)->state(__('pedagogy.visibilities.'.$a['key_visibility'])),
                TextEntry::make('audience')->label(__('pedagogy.audience'))->state(Formats::number((int) $a['audience'])),
            ]),
        ]);
    }

    /** A quiz's questions in their order, the correct choices marked. */
    public function questionsInfolist(Schema $schema): Schema
    {
        $questions = array_map(fn (array $question) => ['prompt' => $question['prompt'],
            'points' => __('pedagogy.points_count', ['points' => Formats::number((float) $question['points'], 2)]),
            'choices' => array_map(fn (array $choice) => ($choice['correct'] ? '✓ ' : '· ').$choice['text'], $question['choices'] ?? [])], $this->sheet()['questions']);
        return $schema->state(['questions' => $questions])->components([
            Section::make(__('pedagogy.questions'))->visible($this->quiz())->collapsible()
                ->headerActions([Action::make('editQuestions')->label(__('pedagogy.edit_questions'))->link()
                    ->url(fn () => ActivityQuestions::getUrl(['activity' => $this->activity]))])
                ->schema([RepeatableEntry::make('questions')->hiddenLabel()->placeholder(__('pedagogy.no_question'))->columns(['default' => 1, 'sm' => 4])->schema([
                    TextEntry::make('prompt')->hiddenLabel()->columnSpan(['sm' => 3]),
                    TextEntry::make('points')->hiddenLabel(),
                    TextEntry::make('choices')->hiddenLabel()->columnSpanFull()->listWithLineBreaks()->placeholder(__('pedagogy.question_kinds.open')),
                ])]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $writes = fn () => $this->writesActivities() && !$this->archived();
        return [
            Action::make('edit')->label(__('school.edit'))->visible($writes)->modalWidth('3xl')
                ->fillForm(fn () => $this->activityForm($this->sheet()))
                ->schema(fn () => $this->activityFields(false, $this->sheet()['kind'], $this->sheet()['group_id'].':'.$this->sheet()['subject_id']))
                ->action(fn (array $data) => $this->changed(fn () => SchoolScreen::call(static::$permissions, ActivityController::class, 'update',
                    [...$this->sentActivity($data, $this->sheet()['kind']), 'version' => $this->sheet()['version']], ['activity' => $this->activity]))),
            Action::make('publish')->label(fn () => __($this->published() ? 'pedagogy.publish_again' : 'pedagogy.publish'))->color('success')->visible($writes)
                ->requiresConfirmation()->modalDescription(fn () => __($this->published() ? 'pedagogy.publish_again_help' : 'pedagogy.publish_activity_help'))
                ->action(fn () => $this->changed(fn () => SchoolScreen::call(static::$permissions, ActivityController::class, 'publish', [], ['activity' => $this->activity]))),
            ActionGroup::make([
                Action::make('publishResults')->label(__('pedagogy.publish_results'))->visible(fn () => $writes() && $this->published())
                    ->modalDescription(__('pedagogy.publish_results_help'))
                    ->schema([Textarea::make('reason')->label(__('pedagogy.republish_reason'))->helperText(__('pedagogy.republish_reason_help'))->maxLength(500)])
                    ->action(fn (array $data) => $this->changed(fn () => SchoolScreen::call(static::$permissions, ResultController::class, 'publish',
                        array_filter(['reason' => $data['reason'] ?? null]), ['activity' => $this->activity]),
                        fn (array $result) => Notification::make()->title(__('pedagogy.results_published', ['count' => Formats::number((int) $result['data']['published'])]))->success())),
                Action::make('publishKey')->label(__('pedagogy.publish_key'))->requiresConfirmation()->modalDescription(__('pedagogy.publish_key_help'))
                    ->visible(fn () => $writes() && $this->published() && $this->quiz() && $this->sheet()['key_visibility'] === 'on_teacher_publish')
                    ->action(fn () => $this->changed(fn () => SchoolScreen::call(static::$permissions, ResultController::class, 'publishKey', [], ['activity' => $this->activity]))),
                Action::make('regrade')->label(__('pedagogy.regrade'))->color('warning')->modalDescription(__('pedagogy.regrade_help'))
                    ->visible(fn () => $writes() && $this->published() && $this->quiz() && app(Permissions::class)->everywhere('lms.activity.manage'))
                    ->schema([$this->reason()])
                    ->action(fn (array $data) => $this->changed(fn () => SchoolScreen::call(static::$permissions, ResultController::class, 'regrade',
                        ['reason' => $data['reason']], ['activity' => $this->activity]))),
                Action::make('archive')->label(__('school.archive'))->color('danger')->requiresConfirmation()->modalDescription(__('pedagogy.archive_activity_help'))
                    ->visible(fn () => $writes() && $this->published())
                    ->action(fn () => $this->changed(fn () => SchoolScreen::call(static::$permissions, ActivityController::class, 'archive',
                        ['version' => $this->sheet()['version']], ['activity' => $this->activity]))),
                Action::make('delete')->label(__('pedagogy.delete_draft'))->color('danger')->requiresConfirmation()->modalDescription(__('pedagogy.delete_draft_help'))
                    ->visible(fn () => $writes() && !$this->published())
                    ->action(function () {
                        SchoolScreen::change(null, fn () => SchoolScreen::call(static::$permissions, ActivityController::class, 'destroy',
                            ['version' => $this->sheet()['version']], ['activity' => $this->activity]));
                        $this->redirect(Activities::getUrl());
                    }),
            ])->label(__('pedagogy.more'))->button()->color('gray'),
        ];
    }

    public function table(Table $table): Table
    {
        return $table->paginated(false)->records(fn () => $this->pupils())->heading(__('pedagogy.results'))
            ->description(fn () => __($this->published() ? 'pedagogy.results_help' : 'pedagogy.results_before_publication'))
            ->emptyStateHeading(fn () => __($this->published() ? 'pedagogy.no_pupil' : 'pedagogy.not_published_yet'))
            ->columns([
                TextColumn::make('pupil')->label(__('schooling.student'))->state(fn (array $record) => $this->personName($record))
                    ->description(fn (array $record) => $record['matricule']),
                TextColumn::make('status')->label(__('school.status'))->badge()->formatStateUsing(fn ($state) => __('pedagogy.mark_states.'.$state))
                    ->color(fn ($state) => match ($state) { 'published' => 'success', 'graded' => 'info', 'to_grade' => 'warning', default => 'gray' }),
                TextColumn::make('work')->label(__('pedagogy.work'))->state(fn (array $record) => $this->work($record))->placeholder(__('pedagogy.nothing_handed_in'))
                    ->color(fn (array $record) => $record['late'] ? 'warning' : null),
                TextColumn::make('points')->label(__('pedagogy.points'))->placeholder(__('dashboard.none'))
                    ->state(fn (array $record) => $record['points'] === null ? null : $this->points($record['points'], $record['max_points'])),
                TextColumn::make('decision')->label(__('pedagogy.decision'))->placeholder(__('dashboard.none'))
                    ->formatStateUsing(fn ($state) => __('pedagogy.decisions.'.$state))->tooltip(fn (array $record) => $record['decision_reason']),
                TextColumn::make('published_points')->label(__('pedagogy.published'))->placeholder(__('pedagogy.not_published'))
                    ->state(fn (array $record) => match ($record['published_status']) {
                        'graded' => $this->points($record['published_points'], $record['max_points']), 'absent_excused' => __('pedagogy.mark_states.absent_excused'), default => null })
                    ->description(fn (array $record) => $record['published_at'] ? Formats::dateTime($record['published_at']) : null),
                TextColumn::make('own_deadline')->label(__('pedagogy.own_deadline'))->placeholder(__('dashboard.none'))
                    ->state(fn (array $record) => Formats::dateTime($record['entry']['deadline_at'] ?? null) ?: null)
                    ->tooltip(fn (array $record) => $record['entry']['extension_reason'] ?? null),
            ])
            ->recordActions([ActionGroup::make([
                Action::make('grade')->label(__('pedagogy.grade'))->modalWidth('2xl')
                    ->visible(fn (array $record) => $this->corrects() && !$this->quiz() && $record['submission'] !== null)
                    ->fillForm(fn (array $record) => ['points' => $record['points'], 'feedback' => $record['feedback']])
                    ->schema(fn (array $record) => [
                        TextEntry::make('links')->label(__('pedagogy.submission_links'))->listWithLineBreaks()->copyable()->state($record['submission']['links'])
                            ->helperText(__('pedagogy.submission_line', ['number' => Formats::number((int) $record['submission']['number']),
                                'at' => Formats::dateTime($record['submission']['submitted_at'])])),
                        $this->pointsField('points', $record['max_points']),
                        Textarea::make('feedback')->label(__('pedagogy.feedback'))->maxLength(4000)->rows(4),
                    ])
                    ->action(fn (array $record, array $data) => $this->changed(fn () => SchoolScreen::call(static::$permissions, ResultController::class, 'grade',
                        ['student_id' => $record['student_id'], 'points' => $this->number($data['points']), 'feedback' => $data['feedback'] ?? null],
                        ['activity' => $this->activity]))),
                Action::make('correct')->label(__('pedagogy.correct_answers'))->modalWidth('3xl')
                    ->visible(fn (array $record) => $this->corrects() && $this->quiz() && $this->openQuestions() && collect($record['attempts'])->contains('status', 'submitted'))
                    ->fillForm(fn (array $record) => ['answers' => $this->corrections($record)['values']])
                    ->schema(fn (array $record) => $this->corrections($record)['fields'])
                    ->action(fn (array $record, array $data) => $this->changed(function () use ($data) {
                        foreach ($data['answers'] ?? [] as $attempt => $questions) {
                            foreach ($questions as $question => $answer) {
                                if (blank($answer['points'] ?? null)) continue;
                                SchoolScreen::call(static::$permissions, ResultController::class, 'correct', ['question_id' => $question,
                                    'points' => $this->number($answer['points']), 'comment' => $answer['comment'] ?? null], ['attempt' => $attempt]);
                            }
                        }
                        return [];
                    })),
                Action::make('decide')->label(__('pedagogy.decide'))->modalDescription(__('pedagogy.decide_help'))->visible(fn () => $this->corrects())
                    ->fillForm(fn (array $record) => ['decision' => $record['decision'] ?? 'none'])
                    ->schema([
                        Select::make('decision')->label(__('pedagogy.decision'))->required()->selectablePlaceholder(false)
                            ->options([...__('pedagogy.decisions'), 'none' => __('pedagogy.decision_none')]),
                        Textarea::make('reason')->label(__('school.reason'))->minLength(3)->maxLength(500)->requiredUnless('decision', 'none'),
                    ])
                    ->action(fn (array $record, array $data) => $this->changed(fn () => SchoolScreen::call(static::$permissions, ResultController::class, 'decide',
                        ['student_id' => $record['student_id'], 'decision' => $data['decision'] === 'none' ? null : $data['decision'], 'reason' => $data['reason'] ?? null],
                        ['activity' => $this->activity]))),
                Action::make('publishOne')->label(__('pedagogy.publish_result'))->color('success')
                    ->visible(fn (array $record) => $this->corrects() && in_array($record['status'], ['graded', 'absent_excused'], true))
                    ->modalDescription(fn (array $record) => __($record['publications'] > 0 ? 'pedagogy.republish_reason_help' : 'pedagogy.publish_result_help'))
                    ->schema(fn (array $record) => $record['publications'] > 0 ? [Textarea::make('reason')->label(__('pedagogy.republish_reason'))->required()->minLength(3)->maxLength(500)] : [])
                    ->action(fn (array $record, array $data) => $this->changed(fn () => SchoolScreen::call(static::$permissions, ResultController::class, 'publish',
                        array_filter(['student_ids' => [$record['student_id']], 'reason' => $data['reason'] ?? null]), ['activity' => $this->activity]))),
                Action::make('extend')->label(__('pedagogy.extend'))->modalDescription(__('pedagogy.extend_help'))
                    ->visible(fn (array $record) => $this->corrects() && $record['entry'] !== null)
                    ->schema([
                        DateTimePicker::make('deadline_at')->label(__('pedagogy.own_deadline'))->required()->seconds(false)->timezone('Africa/Algiers')->minDate(now()),
                        Textarea::make('reason')->label(__('school.reason'))->required()->minLength(3)->maxLength(500),
                    ])
                    ->action(fn (array $record, array $data) => $this->changed(fn () => SchoolScreen::call(static::$permissions, ActivityController::class, 'extend',
                        ['deadline_at' => Carbon::parse($data['deadline_at'], config('app.timezone'))->toIso8601String(), 'reason' => $data['reason'],
                            'version' => $record['entry']['version']], ['entry' => $record['entry']['id']]))),
                Action::make('flag')->label(__('pedagogy.flag_submission'))->color('warning')->modalDescription(__('pedagogy.flag_submission_help'))
                    ->visible(fn (array $record) => $this->corrects() && ($record['submission']['status'] ?? null) === 'current')
                    ->schema([Textarea::make('reason')->label(__('school.reason'))->required()->minLength(3)->maxLength(500)])
                    ->action(fn (array $record, array $data) => $this->changed(fn () => SchoolScreen::call(static::$permissions, HomeworkSubmissionController::class, 'flag',
                        ['reason' => $data['reason']], ['submission' => $record['submission']['id']]))),
            ])]);
    }

    /** Correcting needs a published activity, not archived, and a school open to writing. */
    private function corrects(): bool { return $this->writesActivities() && $this->published() && !$this->archived(); }

    /** Each pupil of the audience: their result, their entry, their last submission or their attempts. */
    private function pupils(): array
    {
        if ($this->pupilRows !== null) return $this->pupilRows;
        if (!$this->published()) return $this->pupilRows = [];
        $at = ['activity' => $this->activity];
        $entries = collect(SchoolScreen::call(static::$permissions, ActivityController::class, 'audience', parameters: $at)['data'])->keyBy('student_id');
        $submissions = $this->quiz() ? collect() : collect(SchoolScreen::call(static::$permissions, HomeworkSubmissionController::class, 'forActivity', parameters: $at)['data'])
            ->groupBy('student_id')->map(fn ($rows) => $rows->first());
        $attempts = $this->quiz() ? collect(SchoolScreen::call(static::$permissions, QuizAttemptController::class, 'forActivity', parameters: $at)['data'])->groupBy('student_id')
            : collect();
        return $this->pupilRows = collect(SchoolScreen::call(static::$permissions, ResultController::class, 'index', parameters: $at)['data'])
            ->mapWithKeys(fn (array $row) => [$row['student_id'] => [...$row, 'id' => $row['student_id'], 'entry' => $entries[$row['student_id']] ?? null,
                'submission' => $submissions[$row['student_id']] ?? null, 'attempts' => ($attempts[$row['student_id']] ?? collect())->values()->all()]])->all();
    }

    /** What the pupil handed in: their last submission, or their attempts and what is left to correct. */
    private function work(array $record): ?string
    {
        if ($record['submission'] !== null) {
            return __('pedagogy.submission_line', ['number' => Formats::number((int) $record['submission']['number']), 'at' => Formats::dateTime($record['submission']['submitted_at'])])
                .($record['late'] ? ' · '.__('pedagogy.late') : '').($record['submission']['status'] === 'inaccessible' ? ' · '.__('pedagogy.flagged') : '');
        }
        $submitted = collect($record['attempts'])->where('status', 'submitted');
        if ($record['attempts'] === []) return null;
        $pending = $submitted->sum('pending_questions');
        return __('pedagogy.attempts_line', ['count' => Formats::number($submitted->count()), 'started' => Formats::number(count($record['attempts']))])
            .($pending > 0 ? ' · '.__('pedagogy.to_correct', ['count' => Formats::number($pending)]) : '');
    }

    private function openQuestions(): bool { return collect($this->sheet()['questions'])->contains('kind', 'open'); }

    /**
     * The open answers of a pupil's submitted attempts, a field of points and a comment for each,
     * as they were corrected so far.
     *
     * @return array{fields: list<mixed>, values: array}
     */
    private function corrections(array $record): array
    {
        $fields = [];
        $values = [];
        foreach (collect($record['attempts'])->where('status', 'submitted') as $attempt) {
            $read = SchoolScreen::call(static::$permissions, ResultController::class, 'attempt', parameters: ['attempt' => $attempt['id']])['data'];
            $entries = [];
            foreach ($read['questions'] as $question) {
                if ($question['kind'] !== 'open') continue;
                $path = 'answers.'.$attempt['id'].'.'.$question['id'];
                $values[$attempt['id']][$question['id']] = ['points' => $question['answer']['points'] ?? null, 'comment' => $question['answer']['comment'] ?? null];
                $entries[] = TextEntry::make($path.'.answer')->label($question['prompt'])->state($question['answer']['answer_text'] ?? __('pedagogy.no_answer'));
                $entries[] = $this->pointsField($path.'.points', $question['points'])->required(false);
                $entries[] = Textarea::make($path.'.comment')->label(__('pedagogy.comment'))->maxLength(2000)->rows(2);
            }
            $fields[] = Section::make(__('pedagogy.attempt_number', ['number' => Formats::number((int) $attempt['number'])]))
                ->description(Formats::dateTime($attempt['submitted_at']))->schema($entries);
        }
        return ['fields' => $fields, 'values' => $values];
    }

    private function pointsField(string $name, string|int|float|null $max): TextInput
    {
        return TextInput::make($name)->label(__('pedagogy.points'))->required()->regex('/^\d{1,5}([.,]\d{1,2})?$/')
            ->suffix('/ '.Formats::number((float) $max, 2))->helperText(__('pedagogy.points_help'));
    }

    private function number(string|int|float $value): string { return str_replace(',', '.', trim((string) $value)); }

    private function points(string|int|float $points, string|int|float $max): string
    {
        return Formats::number((float) $points, 2).' / '.Formats::number((float) $max, 2);
    }

    /** A change, then the sheet read again. */
    private function changed(callable $change, ?callable $done = null): void
    {
        SchoolScreen::change(null, $change, $done);
        $this->sheetRow = null;
        $this->pupilRows = null;
        $this->cacheSchema('detailsInfolist', null);
        $this->cacheSchema('questionsInfolist', null);
    }
}
