<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * FR-PLN-03: a session's conflicts as the database finds them (`school.session_conflicts`, SQL 062),
 * each blocking or a warning, with its message in the reader's language: a blocking one is the
 * refusal the database would give on writing, a warning is told and never refuses.
 */
final class SessionConflicts
{
    /** Those of one session from a group, a subject, a teacher, a room or none, a day and its hours; `$except` the session itself when it changes. */
    public static function of(string $group, string $subject, string $teacher, ?string $room, string $day, string $start, string $end, ?string $except = null): array
    {
        return self::present(DB::select('SELECT conflict, blocking, detail FROM school.session_conflicts(?, ?, ?, ?, school.local_at(?::date, ?::time),
            school.local_at(?::date, ?::time), ?) ORDER BY blocking DESC, conflict', [$group, $subject, $teacher, $room, $day, $start, $day, $end, $except]));
    }

    /** @param iterable<object{conflict: string, blocking: bool, detail: string}> $rows */
    public static function present(iterable $rows): array
    {
        $listed = [];
        foreach ($rows as $row) {
            $detail = json_decode((string) $row->detail, true) ?: [];
            // Hours and counts written for reading: 4, 4,5.
            $replace = array_map(fn ($value) => is_numeric($value) ? Formats::number((float) $value, fmod((float) $value, 1.0) == 0.0 ? 0 : 2) : (string) $value, $detail);
            $listed[] = ['conflict' => $row->conflict, 'blocking' => (bool) $row->blocking, 'detail' => $detail, ...(isset($row->day) ? ['day' => $row->day] : []),
                'message' => __(($row->blocking ? 'errors.' : 'planning.warnings.').$row->conflict, $row->blocking ? [] : $replace)];
        }
        return $listed;
    }
}
