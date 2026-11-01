<?php

namespace App\Filament\School\Pages;

use App\Filament\School\Pages\Concerns\WritesActivities;
use App\Http\Controllers\ProgressController;
use App\Support\Formats;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;

/**
 * FR-LMS-05, « Progression »: a group's subject the teacher teaches (any for the pedagogy manager),
 * each lesson published of its course, and among the group's pupils with access how many opened it,
 * declared it complete, and had their mastery verified by a published result of an activity on it.
 * Three facts kept apart: opening and declaring never verify a mastery (SQL 088). Each lesson's pupils
 * one by one.
 */
final class Progress extends SchoolingPage
{
    use WritesActivities;

    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 36;
    protected static ?string $section = 'pedagogy';
    protected static array $permissions = ['lms.activity.manage'];
    protected static ?string $module = 'lms';
    protected static string|array $routeMiddleware = ['module:lms', 'permission:lms.activity.manage'];
    private ?array $result = null;

    public static function getNavigationLabel(): string { return __('pedagogy.progress'); }
    public function getTitle(): string { return __('pedagogy.progress'); }

    public function table(Table $table): Table
    {
        return $table->paginated(false)->records(fn (array $filters) => $this->lessons($filters))
            ->description(__('pedagogy.progress_help'))
            ->emptyStateHeading(fn () => __($this->targetOptions() === [] ? 'pedagogy.no_target' : 'pedagogy.no_lesson'))
            ->columns([
                TextColumn::make('title')->label(__('pedagogy.lesson'))->description(fn (array $record) => $record['chapter_title']),
                $this->count('opened', 'info'),
                $this->count('declared', 'warning'),
                $this->count('verified', 'success'),
                $this->count('not_verified', 'danger'),
            ])->filters([
                Filter::make('progress')->columnSpanFull()->schema([
                    Select::make('target')->label(__('pedagogy.target'))->searchable()->options(fn () => $this->targetOptions())
                        ->default(fn () => array_key_first($this->targetOptions())),
                ]),
            ])->filtersFormColumns(1)
            ->recordActions([
                Action::make('pupils')->label(__('pedagogy.pupils_progress'))->color('gray')->modalSubmitAction(false)
                    ->modalHeading(fn (array $record) => $record['title'])->modalDescription(__('pedagogy.pupils_progress_help'))
                    ->schema(fn (array $record) => [TextEntry::make('pupils')->hiddenLabel()->listWithLineBreaks()->placeholder(__('pedagogy.no_pupil'))
                        ->state($this->pupilLines($record['id']))]),
            ]);
    }

    /** « 3 / 25 »: pupils in that state among the group's. */
    private function count(string $state, string $color): TextColumn
    {
        return TextColumn::make($state)->label(__('pedagogy.progress_states.'.$state))->badge()->color($color)
            ->formatStateUsing(fn ($state) => Formats::number((int) $state).' / '.Formats::number(count($this->result['data']['pupils'] ?? [])));
    }

    /** The group's progression, read once a request; none until a group's subject is chosen. */
    private function progress(array $filters): ?array
    {
        $target = $filters['progress']['target'] ?? array_key_first($this->targetOptions());
        if ($target === null || !str_contains($target, ':')) return null;
        [$group, $subject] = explode(':', $target, 2);
        return $this->result ??= SchoolScreen::call(static::$permissions, ProgressController::class, 'group', ['subject_id' => $subject], ['group' => $group]);
    }

    /** Each published lesson with its counts. */
    private function lessons(array $filters): array
    {
        $progress = $this->progress($filters)['data'] ?? null;
        if ($progress === null) return [];
        $pupils = collect($progress['pupils']);
        return collect($progress['lessons'])->mapWithKeys(function (array $lesson) use ($pupils) {
            $states = $pupils->map(fn (array $pupil) => $pupil['lessons'][$lesson['lesson_id']] ?? []);
            return [$lesson['lesson_id'] => ['id' => $lesson['lesson_id'], 'title' => $lesson['title'], 'chapter_title' => $lesson['chapter_title'],
                'opened' => $states->filter(fn ($s) => !empty($s['first_opened_at']))->count(),
                'declared' => $states->filter(fn ($s) => !empty($s['completed_at']))->count(),
                'verified' => $states->where('mastery', 'verified')->count(),
                'not_verified' => $states->where('mastery', 'not_verified')->count()]];
        })->all();
    }

    /** « Benali Amine : ouverte le 3 novembre · déclarée terminée · maîtrise vérifiée », pupil by pupil. */
    private function pupilLines(string $lesson): array
    {
        return collect($this->progress($this->tableFilters ?? [])['data']['pupils'] ?? [])
            ->map(function (array $pupil) use ($lesson) {
                $state = $pupil['lessons'][$lesson] ?? [];
                return $this->personName($pupil['student'] ?? []).' : '.implode(' · ', array_filter([
                    !empty($state['first_opened_at']) ? __('pedagogy.opened_on', ['at' => Formats::date($state['first_opened_at'])]) : __('pedagogy.not_opened'),
                    !empty($state['completed_at']) ? __('pedagogy.declared_on', ['at' => Formats::date($state['completed_at'])]) : null,
                    __('pedagogy.mastery_states.'.($state['mastery'] ?? 'none'))]));
            })->sort()->values()->all();
    }
}
