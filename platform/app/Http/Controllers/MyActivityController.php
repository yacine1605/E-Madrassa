<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-LMS-02, FR-LMS-03 for the pupil: the activities they are in the audience of, of a group they
 * learn in today, at their last version, with their own deadline and the state it gives. A quiz's
 * questions and answers are never here (SQL 080); one out of reach is refused alike whether it
 * exists or not (lot 5 §7).
 */
final class MyActivityController
{
    public function index()
    {
        return ['data' => array_map(fn ($row) => $this->present($row),
            DB::select("SELECT * FROM school.my_activities() ORDER BY CASE state WHEN 'open' THEN 0 WHEN 'scheduled' THEN 1 ELSE 2 END, deadline_at, activity_id"))];
    }

    public function show(string $activity)
    {
        abort_unless(Str::isUuid($activity), 404);
        $row = DB::selectOne('SELECT * FROM school.my_activities() WHERE activity_id = ?', [$activity]);
        if (!$row) throw Refusal::of('lms_access_denied', 403);
        return ['data' => $this->present($row)];
    }

    private function present(object $row): array
    {
        return [...(array) $row, 'instructions' => json_decode($row->instructions, true, 64, JSON_THROW_ON_ERROR)];
    }
}
