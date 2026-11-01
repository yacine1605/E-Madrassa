<?php

namespace App\Filament\School\Pages;

use App\Exceptions\Refusal;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\LessonResourceController;
use App\Http\Controllers\ProgressController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\Permissions;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;

/**
 * FR-LMS-01, a course as its pupils read it (`?course=`): its chapters and their lessons published
 * now, each at its last version, with the teachers' resources. A teacher of the course's subject
 * adds a link of the allow-list to a lesson, and withdraws their own; the manager withdraws any.
 * Out of the reader's reach, the course is refused alike whether it exists or not. A pupil's lessons
 * start folded: unfolding one notes it opened; they declare it complete, or no longer, and read
 * whether a published result verified their mastery (FR-LMS-05).
 */
final class CourseReader extends SchoolPage
{
    protected static bool $isDiscovered = true;
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $section = 'pedagogy';
    protected static array $permissions = ['lms.course.read', 'lms.course.manage'];
    protected static ?string $module = 'lms';
    protected static string|array $routeMiddleware = ['module:lms', 'permission:lms.course.read,lms.course.manage'];
    protected string $view = 'filament.school.course-reader';
    #[Locked] public string $course = '';
    private ?array $courseRow = null;
    private ?array $progressRows = null;
    private ?bool $learns = null;

    public function mount(): void
    {
        parent::mount();
        $this->course = (string) request()->query('course', '');
        abort_unless(Str::isUuid($this->course), 404);
        $this->read();
    }

    /** The course and its published lessons, as the reader may read them. */
    public function read(): array
    {
        return $this->courseRow ??= SchoolScreen::call(static::$permissions, CourseController::class, 'lessons', parameters: ['course' => $this->course])['data'];
    }

    public function getTitle(): string
    {
        $course = $this->read();
        return implode(' · ', array_filter([$course['subject_code'], Locales::label($course['subject_labels'] ?? [])]));
    }

    public function getSubheading(): ?string { return $this->read()['description']; }

    /** @return array<string, array{title: string, lessons: list<array>}> the lessons by chapter, in their order */
    public function chapters(): array
    {
        $chapters = [];
        foreach ($this->read()['lessons'] as $lesson) {
            $chapters[$lesson['chapter_id']] ??= ['title' => $lesson['chapter_title'], 'lessons' => []];
            $chapters[$lesson['chapter_id']]['lessons'][] = $lesson;
        }
        return $chapters;
    }

    /** « 45 min », or nothing for a lesson without a length. */
    public function duration(array $lesson): ?string
    {
        return $lesson['duration_minutes'] ? __('pedagogy.minutes', ['minutes' => Formats::number((int) $lesson['duration_minutes'])]) : null;
    }

    /** A pupil reads the course as theirs: their progression is kept and shown (FR-LMS-05). */
    public function learns(): bool
    {
        return $this->learns ??= app(Permissions::class)->can('lms.activity.take') && SchoolScreen::writable(null);
    }

    /** @return array<string, array> the pupil's progression, by lesson */
    private function progress(): array
    {
        return $this->progressRows ??= $this->learns()
            ? collect(SchoolScreen::call(static::$permissions, ProgressController::class, 'mine', parameters: ['course' => $this->course])['data'])->keyBy('lesson_id')->all()
            : [];
    }

    public function declared(array $lesson): bool { return ($this->progress()[$lesson['lesson_id']]['completed_at'] ?? null) !== null; }

    /** Its length, and for a pupil what they did of it: opened, declared complete, mastery verified or not. */
    public function lessonLine(array $lesson): ?string
    {
        $progress = $this->progress()[$lesson['lesson_id']] ?? null;
        $states = $progress === null ? [] : [
            $progress['first_opened_at'] ? __('pedagogy.opened_on', ['at' => Formats::date($progress['first_opened_at'])]) : __('pedagogy.not_opened'),
            $progress['completed_at'] ? __('pedagogy.declared_on', ['at' => Formats::date($progress['completed_at'])]) : null,
            __('pedagogy.mastery_states.'.($progress['mastery'] ?? 'none')),
        ];
        return implode(' · ', array_filter([$this->duration($lesson), ...$states])) ?: null;
    }

    /** What the page tells the server once the pupil unfolds the lesson. */
    public function opening(array $lesson): ?string
    {
        return $this->learns() ? "\$wire.opened('".$lesson['lesson_id']."')" : null;
    }

    /** The pupil unfolded a lesson: noted by the server, quietly; nothing to draw again. */
    #[Renderless]
    public function opened(string $lesson): void
    {
        if (!$this->learns() || !Str::isUuid($lesson)) return;
        try {
            SchoolScreen::call(static::$permissions, ProgressController::class, 'opened', parameters: ['lesson' => $lesson]);
        } catch (Refusal|QueryException) {
            // A lesson withdrawn meanwhile, or access lost: nothing to note.
        }
    }

    public function declareAction(): Action
    {
        return Action::make('declare')->size('sm')->color(fn (array $arguments) => ($arguments['done'] ?? true) ? 'success' : 'gray')
            ->label(fn (array $arguments) => __(($arguments['done'] ?? true) ? 'pedagogy.declare_done' : 'pedagogy.declare_undone'))
            ->tooltip(__('pedagogy.declare_help'))
            ->action(fn (array $arguments) => $this->changed(fn () => SchoolScreen::call(static::$permissions, ProgressController::class, 'complete',
                ['done' => (bool) $arguments['done']], ['lesson' => (string) $arguments['lesson']])));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addResource')->label(__('pedagogy.add_resource'))
                ->visible(fn () => app(Permissions::class)->can('lms.resource.manage') && $this->read()['lessons'] !== [] && SchoolScreen::writable(null))
                ->modalDescription(__('pedagogy.add_resource_help'))
                ->schema([
                    Select::make('lesson_id')->label(__('pedagogy.lesson'))->required()
                        ->options(fn () => collect($this->read()['lessons'])->mapWithKeys(fn (array $lesson) => [$lesson['lesson_id'] => $lesson['title']])->all()),
                    TextInput::make('title')->label(__('pedagogy.title'))->required()->maxLength(200),
                    TextInput::make('url')->label(__('pedagogy.link'))->required()->maxLength(2000)->helperText(__('pedagogy.link_help')),
                ])
                ->action(fn (array $data) => $this->changed(fn () => SchoolScreen::call('lms.resource.manage', LessonResourceController::class, 'store',
                    ['title' => $data['title'], 'url' => trim($data['url'])], ['lesson' => $data['lesson_id']]))),
        ];
    }

    public function withdrawResourceAction(): Action
    {
        return Action::make('withdrawResource')->label(__('pedagogy.withdraw_resource'))->color('danger')->link()->size('sm')->requiresConfirmation()
            ->modalDescription(__('pedagogy.withdraw_resource_help'))
            ->action(fn (array $arguments) => $this->changed(fn () => SchoolScreen::call('lms.resource.manage', LessonResourceController::class, 'withdraw',
                ['version' => (int) $arguments['version']], ['resource' => (string) $arguments['resource']])));
    }

    private function changed(callable $change): void
    {
        SchoolScreen::change(null, $change);
        $this->courseRow = null;
        $this->progressRows = null;
    }
}
