<?php

namespace App\Filament\School\Pages\Concerns;

use App\Http\Controllers\GradingController;
use App\Http\Controllers\ResultController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Illuminate\Support\Facades\DB;

/**
 * The pupil's own published results, marks on their scales and averages (SQL 083, 084), read once
 * per request; the subjects' names, which every member of the school reads.
 */
trait ReadsOwnActivities
{
    private ?array $subjectRows = null;
    private ?array $mineRows = null;
    private ?array $resultRows = null;

    protected function subjectName(?string $subject): string
    {
        $this->subjectRows ??= collect(DB::select('SELECT id, code, labels FROM school.subject WHERE tenant_id = ?', [$this->schoolId]))
            ->mapWithKeys(fn ($row) => [$row->id => $row->code.' · '.Locales::label(json_decode($row->labels, true))])->all();
        return $this->subjectRows[$subject ?? ''] ?? '';
    }

    /** The school year's averages and the marks on their scales, from what is published only. */
    private function mine(): array
    {
        return $this->mineRows ??= SchoolScreen::call('lms.activity.take', GradingController::class, 'mine', ['from' => $this->yearStart(), 'to' => $this->yearEnd()])['data'];
    }

    protected function averages(): array { return $this->mine()['averages']; }

    /** @return array<string, array> the last published result of each activity, by activity */
    protected function results(): array
    {
        return $this->resultRows ??= collect(SchoolScreen::call('lms.activity.take', ResultController::class, 'mine')['data'])->keyBy('activity_id')->all();
    }

    protected function mark(string $activity): ?array
    {
        return collect($this->mine()['marks'])->firstWhere('activity_id', $activity);
    }

    /** « 7,50 / 10,00 », « Absent excusé », or nothing while nothing is published. */
    protected function resultLine(string $activity): ?string
    {
        $result = $this->results()[$activity] ?? null;
        if ($result === null) return null;
        if ($result['status'] !== 'graded') return __('pedagogy.mark_states.'.$result['status']);
        $mark = $this->mark($activity);
        return $mark ? $this->onScale($mark['mark'], $mark['scale_max']) : Formats::number((float) $result['points'], 2).' / '.Formats::number((float) $result['max_points'], 2);
    }

    protected function onScale(string|int|float $value, string|int|float $scale): string
    {
        return Formats::number((float) $value, 2).' / '.Formats::number((float) $scale, 2);
    }
}
