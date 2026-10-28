<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\CourseController;
use App\Http\Controllers\LessonController;
use App\Support\Formats;
use App\Support\LessonDocument;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * FR-LMS-01, a course's outline for the pedagogy manager (`?course=`): its chapters and lessons in
 * their order, each lesson's state (draft, in review, published, scheduled). Chapters and lessons are
 * added, renamed, moved and archived; a lesson is written in the editor, previewed as a pupil will
 * read it, submitted when the school asks for a review, and published, with its chapter or the whole
 * course, now or later, after a preview of what the publication does.
 */
final class CourseOutline extends SchoolingPage
{
    protected static bool $isDiscovered = true;
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $section = 'pedagogy';
    protected static array $permissions = ['lms.course.manage'];
    protected static ?string $module = 'lms';
    protected static string|array $routeMiddleware = ['module:lms', 'permission:lms.course.manage'];
    #[Locked] public string $course = '';
    private ?array $outlineRow = null;

    public function mount(): void
    {
        parent::mount();
        $this->course = (string) request()->query('course', '');
        $this->outline();
    }

    /** The course as the manager sees it; one of another school is not found. */
    public function outline(): array
    {
        abort_unless(Str::isUuid($this->course), 404);
        return $this->outlineRow ??= SchoolScreen::call(static::$permissions, CourseController::class, 'outline', parameters: ['course' => $this->course])['data'];
    }

    public function getTitle(): string
    {
        $course = $this->outline();
        return implode(' · ', array_filter([$course['subject_code'], Locales::label($course['subject_labels'] ?? []), $course['program_code']]));
    }

    public function getSubheading(): ?string { return $this->outline()['description'] ?? __('pedagogy.outline_help'); }

    public function table(Table $table): Table
    {
        return $table->paginated(false)->records(fn () => $this->rows())->emptyStateHeading(__('pedagogy.no_chapter'))
            ->columns([
                TextColumn::make('number')->label(__('pedagogy.number'))->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('title')->label(__('pedagogy.title'))->weight(fn (array $record) => $record['kind'] === 'chapter' ? 'bold' : null)
                    ->description(fn (array $record) => $record['kind'] === 'lesson' && $record['duration_minutes'] ? __('pedagogy.minutes', ['minutes' => Formats::number($record['duration_minutes'])]) : null),
                TextColumn::make('state')->label(__('school.status'))->badge()
                    ->state(fn (array $record) => $record['kind'] === 'lesson' ? __('pedagogy.lesson_states.'.$this->state($record)) : null)
                    ->color(fn (array $record) => match ($record['kind'] === 'lesson' ? $this->state($record) : null) {
                        'published' => 'success', 'scheduled' => 'info', 'in_review' => 'warning', default => 'gray' }),
                TextColumn::make('published_version')->label(__('pedagogy.version'))
                    ->formatStateUsing(fn ($state) => $state === null ? '' : Formats::number((int) $state)),
            ])
            ->headerActions([
                Action::make('addChapter')->label(__('pedagogy.add_chapter'))->visible(fn () => $this->writable('lms.course.manage'))
                    ->schema([TextInput::make('title')->label(__('pedagogy.title'))->required()->maxLength(200)])
                    ->action(fn (array $data) => $this->changed(fn () => SchoolScreen::call('lms.course.manage', CourseController::class, 'storeChapter',
                        $data, ['course' => $this->course]))),
                Action::make('addLesson')->label(__('pedagogy.add_lesson'))->visible(fn () => $this->writable('lms.course.manage') && $this->outline()['chapters'] !== [])
                    ->schema([Select::make('chapter_id')->label(__('pedagogy.chapter'))->required()->options(fn () => $this->chapterOptions())
                        ->default(fn () => array_key_last($this->chapterOptions())),
                        TextInput::make('title')->label(__('pedagogy.title'))->required()->maxLength(200)])
                    ->action(fn (array $data) => $this->changed(fn () => SchoolScreen::call('lms.course.manage', LessonController::class, 'store',
                        ['title' => $data['title']], ['chapter' => $data['chapter_id']]))),
                $this->publishAction('publishCourse', 'course', fn () => $this->course)->label(__('pedagogy.publish_course')),
                Action::make('read')->label(__('pedagogy.read_course'))->color('gray')->url(fn () => CourseReader::getUrl(['course' => $this->course])),
            ])
            ->recordActions([ActionGroup::make([
                // A lesson.
                Action::make('edit')->label(__('school.edit'))->visible(fn (array $record) => $record['kind'] === 'lesson' && $this->writable('lms.course.manage'))
                    ->modalWidth('5xl')
                    ->fillForm(fn (array $record) => $this->lesson($record['id']))
                    ->schema([
                        TextInput::make('title')->label(__('pedagogy.title'))->required()->maxLength(200),
                        Textarea::make('objectives')->label(__('pedagogy.objectives'))->maxLength(2000)->rows(2),
                        Textarea::make('prerequisites')->label(__('pedagogy.prerequisites'))->maxLength(2000)->rows(2),
                        TextInput::make('duration_minutes')->label(__('pedagogy.duration'))->numeric()->integer()->minValue(1)->maxValue(600)->suffix(__('pedagogy.minutes_unit')),
                        // No file is attached, so nothing is saved beside the lesson (the row is the API's, not a model).
                        RichEditor::make('content')->label(__('pedagogy.content'))->json()->fileAttachments(false)->saveRelationshipsUsing(null)->helperText(__('pedagogy.content_help'))
                            ->toolbarButtons([['bold', 'italic', 'underline', 'strike', 'subscript', 'superscript', 'link'], ['h2', 'h3'],
                                ['blockquote', 'codeBlock', 'bulletList', 'orderedList'], ['undo', 'redo']]),
                    ])
                    ->action(fn (array $record, array $data) => $this->changed(fn () => SchoolScreen::call('lms.course.manage', LessonController::class, 'update', [
                        'title' => $data['title'], 'objectives' => $data['objectives'] ?? null, 'prerequisites' => $data['prerequisites'] ?? null,
                        'duration_minutes' => filled($data['duration_minutes'] ?? null) ? (int) $data['duration_minutes'] : null,
                        'content' => LessonDocument::clean(is_array($data['content'] ?? null) ? $data['content'] : null), 'version' => $record['version']],
                        ['lesson' => $record['id']]))),
                Action::make('preview')->label(__('pedagogy.preview'))->color('gray')->visible(fn (array $record) => $record['kind'] === 'lesson')
                    ->modalSubmitAction(false)->modalWidth('4xl')->modalHeading(fn (array $record) => $record['title'])
                    ->modalContent(fn (array $record) => view('filament.school.lesson-preview', ['lesson' => $this->lesson($record['id'])])),
                Action::make('submit')->label(__('pedagogy.submit_lesson'))->color('warning')->requiresConfirmation()->modalDescription(__('pedagogy.submit_lesson_help'))
                    ->visible(fn (array $record) => $record['kind'] === 'lesson' && $record['status'] === 'draft' && $this->reviewed() && $this->writable('lms.course.manage'))
                    ->action(fn (array $record) => $this->changed(fn () => SchoolScreen::call('lms.course.manage', LessonController::class, 'submit', [], ['lesson' => $record['id']]))),
                $this->publishAction('publishLesson', 'lesson')->visible(fn (array $record) => $record['kind'] === 'lesson' && $this->writable('lms.course.manage')),
                // A chapter.
                Action::make('rename')->label(__('pedagogy.rename_chapter'))->visible(fn (array $record) => $record['kind'] === 'chapter' && $this->writable('lms.course.manage'))
                    ->fillForm(fn (array $record) => ['title' => $record['title']])
                    ->schema([TextInput::make('title')->label(__('pedagogy.title'))->required()->maxLength(200)])
                    ->action(fn (array $record, array $data) => $this->changed(fn () => SchoolScreen::call('lms.course.manage', CourseController::class, 'updateChapter',
                        [...$data, 'version' => $record['version']], ['chapter' => $record['id']]))),
                $this->publishAction('publishChapter', 'chapter')->label(__('pedagogy.publish_chapter'))
                    ->visible(fn (array $record) => $record['kind'] === 'chapter' && $record['lessons'] > 0 && $this->writable('lms.course.manage')),
                // Either.
                Action::make('up')->label(__('pedagogy.move_up'))->color('gray')->visible(fn (array $record) => !$record['first'] && $this->writable('lms.course.manage'))
                    ->action(fn (array $record) => $this->move($record, -1)),
                Action::make('down')->label(__('pedagogy.move_down'))->color('gray')->visible(fn (array $record) => !$record['last'] && $this->writable('lms.course.manage'))
                    ->action(fn (array $record) => $this->move($record, 1)),
                Action::make('archive')->label(__('school.archive'))->color('danger')->requiresConfirmation()
                    ->modalDescription(fn (array $record) => __($record['kind'] === 'chapter' ? 'pedagogy.archive_chapter_help' : 'pedagogy.archive_lesson_help'))
                    ->visible(fn () => $this->writable('lms.course.manage'))
                    ->action(fn (array $record) => $this->changed(fn () => $record['kind'] === 'chapter'
                        ? SchoolScreen::call('lms.course.manage', CourseController::class, 'archiveChapter', ['version' => $record['version']], ['chapter' => $record['id']])
                        : SchoolScreen::call('lms.course.manage', LessonController::class, 'archive', ['version' => $record['version']], ['lesson' => $record['id']]))),
            ])]);
    }

    /** A publication of a lesson, a chapter or the course: what it would do, then now or from a moment, in Algiers' time. */
    private function publishAction(string $name, string $scope, ?\Closure $id = null): Action
    {
        $target = fn (?array $record) => $id ? $id() : $record['id'];
        return Action::make($name)->label(__('pedagogy.publish'))->color('success')->visible(fn () => $this->writable('lms.course.manage'))
            ->modalHeading(__('pedagogy.publication'))->modalSubmitActionLabel(__('pedagogy.publish'))
            ->schema(fn (?array $record) => [
                TextEntry::make('preview')->label(__('pedagogy.publication_preview'))->listWithLineBreaks()->state($this->previewLines($scope, $target($record))),
                DateTimePicker::make('visible_from')->label(__('pedagogy.visible_from'))->seconds(false)->timezone('Africa/Algiers')
                    ->minDate(now())->helperText(__('pedagogy.visible_from_help')),
            ])
            ->action(fn (?array $record, array $data) => $this->changed(fn () => SchoolScreen::call('lms.course.manage', CourseController::class, 'publish', [
                'scope' => $scope, 'id' => $target($record),
                'visible_from' => empty($data['visible_from']) ? null : Carbon::parse($data['visible_from'], config('app.timezone'))->toIso8601String()])));
    }

    /** @return list<string> each lesson, and whether it would be published, left as it is, or stop it all */
    private function previewLines(string $scope, string $id): array
    {
        $lines = array_map(fn (array $line) => $line['title'].' : '.($line['problem'] !== null ? $line['message']
            : __($line['publishes'] ? 'pedagogy.will_publish' : 'pedagogy.unchanged', ['version' => Formats::number(((int) $line['published_version']) + 1)])),
            SchoolScreen::call('lms.course.manage', CourseController::class, 'preview', ['scope' => $scope, 'id' => $id])['data']);
        return $lines === [] ? [__('errors.lms_publication_empty')] : $lines;
    }

    /** The chapters and their lessons as rows, in their order, each knowing whether it can move up or down. */
    private function rows(): array
    {
        $rows = [];
        $chapters = $this->outline()['chapters'];
        foreach ($chapters as $c => $chapter) {
            $rows[$chapter['id']] = ['id' => $chapter['id'], 'kind' => 'chapter', 'number' => Formats::number($c + 1), 'title' => $chapter['title'],
                'version' => $chapter['version'], 'status' => null, 'published_version' => null, 'visible_from' => null, 'duration_minutes' => null,
                'lessons' => count($chapter['lessons']), 'first' => $c === 0, 'last' => $c === count($chapters) - 1, 'parent' => $this->course];
            foreach ($chapter['lessons'] as $l => $lesson) {
                $rows[$lesson['id']] = [...$lesson, 'kind' => 'lesson', 'number' => Formats::number($c + 1).'.'.Formats::number($l + 1),
                    'first' => $l === 0, 'last' => $l === count($chapter['lessons']) - 1, 'parent' => $chapter['id'], 'lessons' => 0];
            }
        }
        return $rows;
    }

    private function state(array $lesson): string
    {
        return $lesson['status'] === 'published' && $lesson['scheduled'] ? 'scheduled' : $lesson['status'];
    }

    /** A chapter or a lesson one place up or down among its siblings, the others kept. */
    private function move(array $record, int $by): void
    {
        $siblings = $record['kind'] === 'chapter' ? array_column($this->outline()['chapters'], 'id')
            : array_column(collect($this->outline()['chapters'])->firstWhere('id', $record['parent'])['lessons'], 'id');
        $at = array_search($record['id'], $siblings, true);
        [$siblings[$at], $siblings[$at + $by]] = [$siblings[$at + $by], $siblings[$at]];
        $this->changed(fn () => $record['kind'] === 'chapter'
            ? SchoolScreen::call('lms.course.manage', CourseController::class, 'orderChapters', ['chapters' => $siblings], ['course' => $this->course])
            : SchoolScreen::call('lms.course.manage', LessonController::class, 'order', ['lessons' => $siblings], ['chapter' => $record['parent']]));
    }

    private function lesson(string $id): array
    {
        return SchoolScreen::call('lms.course.manage', LessonController::class, 'show', parameters: ['lesson' => $id])['data'];
    }

    /** @return array<string, string> the chapters in use, in their order */
    private function chapterOptions(): array
    {
        return collect($this->outline()['chapters'])->values()->mapWithKeys(fn (array $chapter, int $i) => [$chapter['id'] => Formats::number($i + 1).'. '.$chapter['title']])->all();
    }

    private function reviewed(): bool
    {
        return (bool) json_decode((string) DB::selectOne("SELECT school.setting_value('lms.lesson.review_required')::text AS v")->v);
    }

    /** A change, then the outline read again. */
    private function changed(callable $change): void
    {
        SchoolScreen::change(null, $change);
        $this->outlineRow = null;
    }
}
