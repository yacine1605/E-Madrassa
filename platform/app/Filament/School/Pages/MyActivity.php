<?php

namespace App\Filament\School\Pages;

use App\Filament\School\Pages\Concerns\ReadsOwnActivities;
use App\Http\Controllers\HomeworkSubmissionController;
use App\Http\Controllers\MyActivityController;
use App\Http\Controllers\QuizAttemptController;
use App\Support\Formats;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * FR-LMS-02 to FR-LMS-04, one of the pupil's activities (`?activity=`): its dates (their own
 * deadline), instructions and settings; their result once published, with the teacher's feedback.
 * A homework is handed in as one to five links of the school's allow-list, each submission a
 * version acknowledged at the server's time, a flagged link replaced within its delay. A quiz is
 * started or resumed, and each submitted attempt read back. One out of reach is refused alike
 * whether it exists or not (lot 5 §7).
 */
final class MyActivity extends SchoolingPage
{
    use ReadsOwnActivities;

    protected static bool $isDiscovered = true;
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $section = 'pedagogy';
    protected static array $permissions = ['lms.activity.take'];
    protected static ?string $module = 'lms';
    protected static string|array $routeMiddleware = ['module:lms', 'permission:lms.activity.take'];
    protected string $view = 'filament.school.my-activity';
    #[Locked] public string $activity = '';
    /** The idempotency key of the next submission or start: a retry of the same click sends the same one. */
    #[Locked] public string $key = '';
    private ?array $activityRow = null;
    private ?array $workRows = null;

    public function mount(): void
    {
        parent::mount();
        $this->activity = (string) request()->query('activity', '');
        $this->key = (string) Str::uuid();
        $this->own();
    }

    public function own(): array
    {
        abort_unless(Str::isUuid($this->activity), 404);
        return $this->activityRow ??= SchoolScreen::call(static::$permissions, MyActivityController::class, 'show', parameters: ['activity' => $this->activity])['data'];
    }

    public function getTitle(): string { return $this->own()['title']; }
    public function getSubheading(): ?string { return __('pedagogy.kinds.'.$this->own()['kind']).' · '.$this->subjectName($this->own()['subject_id']); }

    private function quiz(): bool { return $this->own()['kind'] === 'quiz'; }

    /** The pupil's submissions, newest first, or their attempts. */
    private function work(): array
    {
        return $this->workRows ??= SchoolScreen::call(static::$permissions, $this->quiz() ? QuizAttemptController::class : HomeworkSubmissionController::class,
            'index', parameters: ['activity' => $this->activity])['data'];
    }

    public function detailsInfolist(Schema $schema): Schema
    {
        $a = $this->own();
        $quiz = $this->quiz();
        $result = $this->results()[$this->activity] ?? null;
        $mark = $this->mark($this->activity);
        return $schema->components([
            Section::make(__('pedagogy.my_result'))->visible($result !== null)->columns(['default' => 1, 'sm' => 3])->schema([
                TextEntry::make('result')->label(__('pedagogy.my_result'))->weight('bold')->state($this->resultLine($this->activity)),
                TextEntry::make('passed')->label(__('pedagogy.pass_mark'))->badge()->visible($mark !== null)
                    ->state($mark && $mark['passed'] ? __('pedagogy.passed') : __('pedagogy.not_passed'))->color($mark && $mark['passed'] ? 'success' : 'warning'),
                TextEntry::make('published_at')->label(__('pedagogy.published_at'))->state(Formats::dateTime($result['published_at'] ?? null)),
                TextEntry::make('feedback')->label(__('pedagogy.feedback'))->columnSpanFull()->visible(filled($result['feedback'] ?? null))->state($result['feedback'] ?? null),
            ]),
            Section::make(__('pedagogy.settings'))->columns(['default' => 2, 'sm' => 4])->schema([
                TextEntry::make('state')->label(__('school.status'))->badge()->state(__('pedagogy.activity_states.'.$a['state'])),
                TextEntry::make('opens_at')->label(__('pedagogy.opens_at'))->state(Formats::dateTime($a['opens_at']) ?: __('pedagogy.at_publication')),
                TextEntry::make('deadline_at')->label(__('pedagogy.deadline_at'))->state(Formats::dateTime($a['deadline_at']))
                    ->helperText($a['extended'] ? __('pedagogy.extended') : null),
                TextEntry::make('max_points')->label(__('pedagogy.max_points'))->state(Formats::number((float) $a['max_points'], 2).' · '.__('pedagogy.coefficient').' '
                    .Formats::number((float) $a['coefficient'], 2)),
                TextEntry::make('duration')->label(__('pedagogy.duration_minutes'))->visible($quiz)
                    ->state(__('pedagogy.minutes', ['minutes' => Formats::number((int) $a['duration_minutes'])])),
                TextEntry::make('attempts')->label(__('pedagogy.max_attempts'))->visible($quiz)->state(Formats::number((int) $a['max_attempts'])),
                TextEntry::make('late_policy')->label(__('pedagogy.late_policy'))->visible(!$quiz)->state(__('pedagogy.late_policies.'.$a['late_policy'])),
                TextEntry::make('closes_at')->label(__('pedagogy.closes_at'))->visible(!$quiz && $a['closes_at'] !== $a['deadline_at'])->state(Formats::dateTime($a['closes_at'])),
            ]),
        ]);
    }

    /** A homework's submissions, or a quiz's attempts, each submitted one read back. */
    public function workInfolist(Schema $schema): Schema
    {
        $flagged = $this->replaceable();
        if ($this->quiz()) {
            $rows = array_map(fn (array $attempt) => ['id' => $attempt['id'], 'number' => __('pedagogy.attempt_number', ['number' => Formats::number((int) $attempt['number'])]),
                'state' => __('pedagogy.attempt_states.'.($attempt['status'] === 'submitted' && $attempt['closed_by'] === 'timer' ? 'closed' : $attempt['status'])),
                'at' => Formats::dateTime($attempt['submitted_at'] ?? $attempt['started_at']), 'submitted' => $attempt['status'] === 'submitted'], $this->work());
            return $schema->state(['work' => $rows])->components([Section::make(__('pedagogy.my_attempts'))->schema([
                RepeatableEntry::make('work')->hiddenLabel()->placeholder(__('pedagogy.no_attempt'))->columns(['default' => 2, 'sm' => 4])->schema([
                    TextEntry::make('number')->hiddenLabel()->weight('bold'),
                    TextEntry::make('state')->hiddenLabel()->badge(),
                    TextEntry::make('at')->hiddenLabel(),
                    TextEntry::make('id')->hiddenLabel()->formatStateUsing(fn () => __('pedagogy.open_attempt'))->color('primary')
                        ->url(fn ($state) => QuizAttempt::getUrl(['attempt' => $state])),
                ]),
            ])]);
        }
        $rows = array_map(fn (array $submission) => ['number' => __('pedagogy.submission_line', ['number' => Formats::number((int) $submission['number']),
                'at' => Formats::dateTime($submission['submitted_at'])]),
            'state' => __('pedagogy.submission_states.'.$submission['status']).($submission['late'] ? ' · '.__('pedagogy.late') : ''),
            'links' => $submission['links'],
            'flag' => $submission['status'] === 'inaccessible' ? __('pedagogy.flag_line', ['reason' => $submission['flag_reason'],
                'until' => Formats::dateTime($submission['replace_until'])]) : null], $this->work());
        return $schema->state(['work' => $rows])->components([
            Callout::make(__('pedagogy.replace_link'))->warning()->visible($flagged !== null)
                ->description($flagged ? __('pedagogy.flag_line', ['reason' => $flagged['flag_reason'], 'until' => Formats::dateTime($flagged['replace_until'])]) : null),
            Section::make(__('pedagogy.my_submissions'))->description(__('pedagogy.my_submissions_help'))->schema([
                RepeatableEntry::make('work')->hiddenLabel()->placeholder(__('pedagogy.nothing_handed_in'))->columns(['default' => 1, 'sm' => 2])->schema([
                    TextEntry::make('number')->hiddenLabel()->weight('bold'),
                    TextEntry::make('state')->hiddenLabel()->badge(),
                    TextEntry::make('links')->hiddenLabel()->listWithLineBreaks()->columnSpanFull()->extraAttributes(['dir' => 'ltr']),
                    TextEntry::make('flag')->hiddenLabel()->columnSpanFull()->color('warning')->hidden(fn ($state) => blank($state)),
                ]),
            ]),
        ]);
    }

    /** The last submission, flagged, while it may still be replaced. */
    private function replaceable(): ?array
    {
        $last = $this->quiz() ? null : ($this->work()[0] ?? null);
        return $last && $last['status'] === 'inaccessible' && $last['replace_until'] && now()->lt($last['replace_until']) ? $last : null;
    }

    protected function getHeaderActions(): array
    {
        $open = fn () => SchoolScreen::writable(null) && $this->own()['state'] === 'open';
        return [
            Action::make('handIn')->label(fn () => __($this->work() === [] ? 'pedagogy.hand_in' : 'pedagogy.hand_in_again'))->modalWidth('2xl')
                ->visible(fn () => !$this->quiz() && ($open() || (SchoolScreen::writable(null) && $this->replaceable() !== null)))
                ->modalDescription(__('pedagogy.hand_in_help'))
                ->schema([Textarea::make('links')->label(__('pedagogy.submission_links'))->required()->rows(3)->maxLength(10000)
                    ->placeholder(__('pedagogy.link_placeholder'))->helperText(__('pedagogy.links_help'))->extraInputAttributes(['dir' => 'ltr'])])
                ->action(fn (array $data) => $this->changed(fn () => SchoolScreen::call(static::$permissions, HomeworkSubmissionController::class, 'store',
                    ['idempotency_key' => $this->key, 'links' => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $data['links']))))],
                    ['activity' => $this->activity]),
                    fn () => Notification::make()->title(__('pedagogy.handed_in'))->body(__('pedagogy.handed_in_help'))->success())),
            Action::make('start')->label(fn () => __(collect($this->work())->contains('status', 'in_progress') ? 'pedagogy.resume_attempt' : 'pedagogy.start_attempt'))
                ->visible(fn () => $this->quiz() && $open() && (collect($this->work())->contains('status', 'in_progress')
                    || count($this->work()) < (int) $this->own()['max_attempts']))
                ->requiresConfirmation(fn () => !collect($this->work())->contains('status', 'in_progress'))
                ->modalDescription(fn () => __('pedagogy.start_attempt_help', ['minutes' => Formats::number((int) $this->own()['duration_minutes'])]))
                ->action(function () {
                    $attempt = SchoolScreen::change(null, fn () => SchoolScreen::call(static::$permissions, QuizAttemptController::class, 'start',
                        ['idempotency_key' => $this->key], ['activity' => $this->activity]), fn () => Notification::make()->title(__('pedagogy.attempt_started'))->info());
                    $this->redirect(QuizAttempt::getUrl(['attempt' => $attempt['data']['id']]));
                }),
        ];
    }

    /** A change, a new key for the next one, then the activity read again. */
    private function changed(callable $change, ?callable $done = null): void
    {
        SchoolScreen::change(null, $change, $done);
        $this->key = (string) Str::uuid();
        $this->activityRow = null;
        $this->workRows = null;
        $this->cacheSchema('detailsInfolist', null);
        $this->cacheSchema('workInfolist', null);
    }

    /** The instructions' document, drawn as the lessons are, without any HTML of its own. */
    public function instructions(): ?array
    {
        $document = $this->own()['instructions'] ?? null;
        $empty = !is_array($document) || collect($document['content'] ?? [])->every(fn (array $node) => ($node['content'] ?? []) === []);
        return $empty ? null : $document;
    }
}
