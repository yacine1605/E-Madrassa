<?php

namespace App\Filament\School\Pages;

use App\Exceptions\Refusal;
use App\Http\Controllers\MyActivityController;
use App\Http\Controllers\QuizAttemptController;
use App\Http\Controllers\ResultController;
use App\Support\DatabaseErrors;
use App\Support\Formats;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * FR-LMS-03, a quiz attempt (`?attempt=`) as its pupil takes it: the time left as the server counts
 * it (shown down to the second, read again every 30 seconds), each answer saved at the server's time
 * as soon as it is given, refused after the end; the attempt resumed as it was after a cut, submitted
 * once, closed by the server at its end. Once submitted, read back: the pupil's answers, the key when
 * it is due, the points and the teacher's comments once the result is published (SQL 081, 083).
 */
final class QuizAttempt extends SchoolPage
{
    protected static bool $isDiscovered = true;
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $section = 'pedagogy';
    protected static array $permissions = ['lms.activity.take'];
    protected static ?string $module = 'lms';
    protected static string|array $routeMiddleware = ['module:lms', 'permission:lms.activity.take'];
    protected string $view = 'filament.school.quiz-attempt';
    #[Locked] public string $attempt = '';
    #[Locked] public string $key = '';
    /** The answers as the form holds them, by question. */
    public ?array $answers = [];
    /** The seconds left as the server last counted them; the page counts down from there, never trusted back. */
    public int $secondsLeft = 0;
    private ?array $attemptRow = null;
    private ?array $reviewRow = null;

    public function mount(): void
    {
        parent::mount();
        $this->attempt = (string) request()->query('attempt', '');
        $this->key = (string) Str::uuid();
        $this->secondsLeft = (int) $this->current()['seconds_left'];
        if ($this->taking()) $this->form->fill($this->saved());
    }

    /** The attempt as the server sees it now; one of another pupil is refused alike whether it exists or not. */
    public function current(): array
    {
        abort_unless(Str::isUuid($this->attempt), 404);
        return $this->attemptRow ??= SchoolScreen::call(static::$permissions, QuizAttemptController::class, 'show', parameters: ['attempt' => $this->attempt])['data'];
    }

    public function taking(): bool { return $this->current()['status'] === 'in_progress' && SchoolScreen::writable(null); }

    public function getTitle(): string
    {
        try {
            $title = SchoolScreen::call(static::$permissions, MyActivityController::class, 'show', parameters: ['activity' => $this->current()['activity_id']])['data']['title'];
        } catch (Refusal) {
            $title = null;
        }
        return implode(' · ', array_filter([$title, __('pedagogy.attempt_number', ['number' => Formats::number((int) $this->current()['number'])])]));
    }

    /** Read again: the time left, and the review once the server closed the attempt. */
    public function refresh(): void
    {
        $this->attemptRow = null;
        $this->reviewRow = null;
        $this->secondsLeft = (int) $this->current()['seconds_left'];
        $this->cacheSchema('reviewInfolist', null);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('answers')->components($this->taking() ? array_map(fn (array $question) => $this->field($question), $this->current()['questions'] ?? []) : []);
    }

    private function field(array $question): mixed
    {
        $id = $question['id'];
        $label = Formats::number((int) $question['position']).'. '.$question['prompt'];
        $points = __('pedagogy.points_count', ['points' => Formats::number((float) $question['points'], 2)]);
        $choices = collect($question['choices'] ?? [])->mapWithKeys(fn (array $choice) => [$choice['id'] => $choice['text']])->all();
        return match ($question['kind']) {
            'single' => Radio::make($id)->label($label)->helperText($points)->options($choices)->live()
                ->afterStateUpdated(fn ($state) => $this->save($id, ['choices' => $state === null ? [] : [$state]])),
            'multiple' => CheckboxList::make($id)->label($label)->helperText($points.' · '.__('pedagogy.several_choices'))->options($choices)->live()
                ->afterStateUpdated(fn ($state) => $this->save($id, ['choices' => array_values($state ?? [])])),
            default => Textarea::make($id)->label($label)->helperText($points.' · '.__('pedagogy.saved_on_leave'))->rows(4)->maxLength(20000)->live(onBlur: true)
                ->afterStateUpdated(fn ($state) => $this->save($id, ['answer_text' => $state])),
        };
    }

    /** The answers saved so far, as the form holds them. */
    private function saved(): array
    {
        $kinds = collect($this->current()['questions'] ?? [])->pluck('kind', 'id');
        return collect($this->current()['answers'])->mapWithKeys(fn (array $answer) => [$answer['question_id'] => match ($kinds[$answer['question_id']] ?? null) {
            'single' => $answer['choices'][0] ?? null, 'multiple' => $answer['choices'], default => $answer['answer_text'] }])->all();
    }

    /** One answer, at the server's time; after the end, refused and the attempt read again. */
    private function save(string $question, array $data): void
    {
        try {
            $saved = SchoolScreen::call(static::$permissions, QuizAttemptController::class, 'answer', $data, ['attempt' => $this->attempt, 'question' => $question])['data'];
            Notification::make()->title(__('pedagogy.answer_saved', ['at' => Formats::dateTime($saved['saved_at'])]))->success()->duration(2000)->send();
        } catch (Refusal $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        } catch (QueryException $e) {
            Notification::make()->title((DatabaseErrors::classify($e) ?? throw $e)[2])->danger()->send();
        }
        $this->refresh();
    }

    public function submitAction(): Action
    {
        return Action::make('submit')->label(__('pedagogy.submit_attempt'))->color('success')->visible(fn () => $this->taking())
            ->requiresConfirmation()->modalDescription(__('pedagogy.submit_attempt_help'))
            ->action(function () {
                SchoolScreen::change(null, fn () => SchoolScreen::call(static::$permissions, QuizAttemptController::class, 'submit',
                    ['idempotency_key' => $this->key], ['attempt' => $this->attempt]), fn () => Notification::make()->title(__('pedagogy.attempt_submitted'))->success());
                $this->refresh();
            });
    }

    public function backAction(): Action
    {
        return Action::make('back')->label(__('pedagogy.back_to_activity'))->color('gray')
            ->url(fn () => MyActivity::getUrl(['activity' => $this->current()['activity_id']]));
    }

    /** A submitted attempt read back. */
    public function reviewInfolist(Schema $schema): Schema
    {
        if ($this->taking()) return $schema->components([]);
        $this->reviewRow ??= SchoolScreen::call(static::$permissions, ResultController::class, 'review', parameters: ['attempt' => $this->attempt])['data'];
        $review = $this->reviewRow;
        $rows = array_map(function (array $question) use ($review) {
            $texts = collect($question['choices'] ?? [])->pluck('text', 'id');
            $answer = $question['answer'] ?? [];
            $mine = $question['kind'] === 'open' ? ($answer['answer_text'] ?? null) : $texts->only($answer['choices'] ?? [])->values()->all();
            return ['prompt' => Formats::number((int) $question['position']).'. '.$question['prompt'], 'mine' => $mine ?: __('pedagogy.no_answer'),
                'key' => $review['key_visible'] && $question['kind'] !== 'open' ? collect($question['choices'])->where('correct', true)->pluck('text')->all() : null,
                'points' => $review['result_published'] && isset($answer['points']) ? Formats::number((float) $answer['points'], 2).' / '.Formats::number((float) $question['points'], 2) : null,
                'comment' => $review['result_published'] ? ($answer['comment'] ?? null) : null];
        }, $review['questions'] ?? []);
        return $schema->state(['questions' => $rows])->components([
            Callout::make(__('pedagogy.attempt_closed'))->info()->description(__($review['result_published'] ? 'pedagogy.review_published' : 'pedagogy.review_waiting')
                .' '.__($review['key_visible'] ? 'pedagogy.review_key_shown' : 'pedagogy.review_key_hidden')),
            Section::make(__('pedagogy.my_answers'))->schema([RepeatableEntry::make('questions')->hiddenLabel()->columns(['default' => 1, 'sm' => 2])->schema([
                TextEntry::make('prompt')->hiddenLabel()->weight('bold')->columnSpanFull(),
                TextEntry::make('mine')->label(__('pedagogy.my_answer'))->listWithLineBreaks(),
                TextEntry::make('key')->label(__('pedagogy.key'))->listWithLineBreaks()->color('success')->hidden(fn ($state) => $state === null),
                TextEntry::make('points')->label(__('pedagogy.points'))->hidden(fn ($state) => $state === null),
                TextEntry::make('comment')->label(__('pedagogy.comment'))->hidden(fn ($state) => blank($state)),
            ])]),
        ]);
    }
}
