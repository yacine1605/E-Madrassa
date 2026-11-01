<?php

namespace App\Filament\School\Pages\Concerns;

use App\Support\LessonDocument;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lot 5's activity forms: a quiz or a homework of a group's subject the writer teaches today (every
 * one for the pedagogy manager, SQL 085), its dates in Algiers' time, its points, its attempts or its
 * late policy, when its results and its key are shown. Every rule is the database's (SQL 080).
 */
trait WritesActivities
{
    private ?array $targetRows = null;

    /** @return array<string, string> « group:subject » of what the writer may write an activity for today */
    protected function targetOptions(): array
    {
        $this->targetRows ??= DB::select('SELECT * FROM school.activity_targets()');
        return collect($this->targetRows)->mapWithKeys(fn ($row) => [$row->group_id.':'.$row->subject_id => $row->group_code.' · '
            .Locales::label(json_decode($row->group_labels, true)).' — '.$row->subject_code.' · '.Locales::label(json_decode($row->subject_labels, true))])->all();
    }

    /** Neither archived nor read-only: the database checks who teaches the group. */
    protected function writesActivities(): bool { return SchoolScreen::writable(null); }

    /** @return array<string, string> the lessons published of a group's course in a subject, « group:subject », that an activity may be on */
    protected function lessonOptions(?string $target): array
    {
        if ($target === null || !str_contains($target, ':')) return [];
        try {
            return collect(DB::select('SELECT * FROM school.activity_lessons(?, ?)', explode(':', $target, 2)))
                ->mapWithKeys(fn ($row) => [$row->lesson_id => $row->chapter_title.' · '.$row->title])->all();
        } catch (QueryException) {
            return [];
        }
    }

    /** The fields of an activity; its group, subject and kind only when it is created, its lesson among its group's course's. */
    protected function activityFields(bool $create, ?string $kind = null, ?string $target = null): array
    {
        $quiz = fn (Get $get) => ($create ? $get('kind') : $kind) === 'quiz';
        $moment = fn (string $name) => DateTimePicker::make($name)->label(__('pedagogy.'.$name))->seconds(false)->timezone('Africa/Algiers');
        return [
            ...($create ? [
                Select::make('target')->label(__('pedagogy.target'))->required()->searchable()->options(fn () => $this->targetOptions())->helperText(__('pedagogy.target_help'))->live(),
                Radio::make('kind')->label(__('pedagogy.kind'))->required()->inline()->default('homework')->options(__('pedagogy.kinds'))->live(),
            ] : []),
            TextInput::make('title')->label(__('pedagogy.title'))->required()->maxLength(200),
            Select::make('lesson_id')->label(__('pedagogy.lesson'))->searchable()->helperText(__('pedagogy.activity_lesson_help'))
                ->options(fn (Get $get) => $this->lessonOptions($create ? $get('target') : $target)),
            RichEditor::make('instructions')->label(__('pedagogy.instructions'))->json()->fileAttachments(false)->saveRelationshipsUsing(null)
                ->helperText(__('pedagogy.content_help'))->toolbarButtons([['bold', 'italic', 'underline', 'link'], ['bulletList', 'orderedList'], ['undo', 'redo']]),
            $moment('opens_at')->helperText(__('pedagogy.opens_at_help')),
            $moment('deadline_at')->required()->minDate(now())->helperText(__('pedagogy.deadline_at_help')),
            TextInput::make('duration_minutes')->label(__('pedagogy.duration_minutes'))->numeric()->integer()->minValue(1)->maxValue(600)
                ->suffix(__('pedagogy.minutes_unit'))->visible($quiz)->required($quiz),
            TextInput::make('max_points')->label(__('pedagogy.max_points'))->required()->default('20')->regex('/^\d{1,5}([.,]\d{1,2})?$/')
                ->helperText(fn (Get $get) => $quiz($get) ? __('pedagogy.max_points_quiz_help') : null),
            TextInput::make('coefficient')->label(__('pedagogy.coefficient'))->required()->default('1')->regex('/^\d{1,3}([.,]\d{1,2})?$/'),
            TextInput::make('max_attempts')->label(__('pedagogy.max_attempts'))->numeric()->integer()->minValue(1)->maxValue(20)->default(1)->visible($quiz),
            Select::make('selection')->label(__('pedagogy.selection'))->options(__('pedagogy.selections'))->default('latest')->selectablePlaceholder(false)->visible($quiz),
            Select::make('late_policy')->label(__('pedagogy.late_policy'))->options(__('pedagogy.late_policies'))->default('refuse')->selectablePlaceholder(false)
                ->live()->visible(fn (Get $get) => !$quiz($get)),
            $moment('late_until')->visible(fn (Get $get) => !$quiz($get) && $get('late_policy') === 'accept_until')
                ->required(fn (Get $get) => !$quiz($get) && $get('late_policy') === 'accept_until'),
            TextInput::make('replacement_hours')->label(__('pedagogy.replacement_hours'))->numeric()->integer()->minValue(1)->maxValue(720)->default(48)
                ->helperText(__('pedagogy.replacement_hours_help'))->visible(fn (Get $get) => !$quiz($get)),
            Select::make('results_visibility')->label(__('pedagogy.results_visibility'))->options(__('pedagogy.visibilities'))->default('on_teacher_publish')
                ->selectablePlaceholder(false),
            Select::make('key_visibility')->label(__('pedagogy.key_visibility'))->options(__('pedagogy.visibilities'))->default('after_deadline')
                ->selectablePlaceholder(false)->visible($quiz),
        ];
    }

    /** As the API takes them: moments with their offset, numbers with a point, a quiz's fields only for a quiz. */
    protected function sentActivity(array $data, ?string $kind = null): array
    {
        if (isset($data['target'])) {
            [$data['group_id'], $data['subject_id']] = explode(':', $data['target']);
            unset($data['target']);
        }
        $kind = $data['kind'] ?? $kind;
        foreach (['opens_at', 'deadline_at', 'late_until'] as $moment) {
            if (array_key_exists($moment, $data)) $data[$moment] = blank($data[$moment]) ? null : Carbon::parse($data[$moment], config('app.timezone'))->toIso8601String();
        }
        foreach (['max_points', 'coefficient'] as $number) if (isset($data[$number])) $data[$number] = str_replace(',', '.', trim((string) $data[$number]));
        $data['instructions'] = LessonDocument::clean(is_array($data['instructions'] ?? null) ? $data['instructions'] : null);
        if ($kind === 'quiz') {
            $data['max_attempts'] = (int) ($data['max_attempts'] ?? 1);
            unset($data['late_policy'], $data['late_until'], $data['replacement_hours']);
        } else {
            $data['duration_minutes'] = null;
            if (($data['late_policy'] ?? 'refuse') === 'refuse') $data['late_until'] = null;
            if (isset($data['replacement_hours'])) $data['replacement_hours'] = (int) $data['replacement_hours'];
            unset($data['max_attempts'], $data['selection'], $data['key_visibility']);
        }
        return $data;
    }

    /** An activity's working copy as its form shows it. */
    protected function activityForm(array $activity): array
    {
        return collect($activity)->only(['title', 'instructions', 'opens_at', 'deadline_at', 'duration_minutes', 'max_points', 'coefficient', 'max_attempts',
            'selection', 'late_policy', 'late_until', 'replacement_hours', 'results_visibility', 'key_visibility', 'lesson_id'])->all();
    }
}
