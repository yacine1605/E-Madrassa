<?php

namespace App\Exports;

use App\Http\Controllers\GradingController;
use App\Support\Formats;

/**
 * FR-LMS-04: a group's gradebook in a subject over a period, as a simple list of marks (no official
 * report card): a row per pupil and activity, with its state, raw points, maximum, coefficient and
 * mark on its scale, and the pupil's average from the published marks. For the teacher of the
 * group's subject today or the pedagogy manager (SQL 084).
 */
final class GradebookExport implements Dataset
{
    public function __construct(private GradingController $grading) {}

    public function permission(): string { return 'lms.activity.manage'; }

    public function title(string $locale): string { return __('pedagogy.gradebook', [], $locale); }

    public function columns(string $locale): array
    {
        return array_map(fn (string $key) => ['key' => $key, 'label' => __('pedagogy.gradebook_columns.'.$key, [], $locale)],
            ['matricule', 'pupil', 'activity', 'deadline', 'status', 'points', 'max_points', 'coefficient', 'mark', 'average']);
    }

    public function filters(): array
    {
        return ['group_id' => 'required|uuid', 'subject_id' => 'required|uuid', 'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from'];
    }

    public function count(array $filters): int
    {
        return array_sum(array_map(fn (array $pupil) => count($pupil['marks']), $this->book($filters)));
    }

    public function rows(array $filters, string $locale): iterable
    {
        foreach ($this->book($filters) as $pupil) {
            $name = trim(($pupil['student']['last_name_lat'] ?? $pupil['student']['last_name_ar'] ?? '').' '.($pupil['student']['first_name_lat'] ?? $pupil['student']['first_name_ar'] ?? ''));
            foreach ($pupil['marks'] as $mark) {
                yield ['matricule' => $pupil['student']['matricule'] ?? null, 'pupil' => $name, 'activity' => $mark['title'],
                    'deadline' => Formats::dateTime($mark['deadline_at'], $locale), 'status' => __('pedagogy.mark_states.'.$mark['status'], [], $locale),
                    'points' => $mark['published_points'], 'max_points' => $mark['max_points'], 'coefficient' => $mark['coefficient'], 'mark' => $mark['published_mark'],
                    'average' => $pupil['average']['average'] ?? null];
            }
        }
    }

    private function book(array $filters): array
    {
        return $this->grading->book($filters['group_id'], $filters['subject_id'], $filters['from'], $filters['to']);
    }
}
