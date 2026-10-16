<?php

namespace App\Filament\School\Pages\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * FR-STF-03: who changes a teacher's availability and days off, and whether their change is in
 * force at once or waits for an approver who is not that teacher. The database decides again (SQL 034).
 */
trait DecidesAvailability
{
    private ?bool $inForceAtOnce = null;

    private function changeable(): bool { return $this->changeableWith('staff.availability.manage'); }

    /** An approver of this record who is not its own teacher (FR-IAM-05). */
    private function decidable(): bool
    {
        return $this->changeableWith('staff.availability.approve') && $this->member()['user_id'] !== auth()->id();
    }

    /** Whether the reader's change is in force at once, or waits for an approver. */
    private function inForceAtOnce(): bool
    {
        return $this->inForceAtOnce ??= (bool) DB::selectOne('SELECT school.availability_in_force(?) AS now', [$this->staff])->now;
    }
}
