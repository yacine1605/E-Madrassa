<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Support\CurrentSchool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-LMS-02: a homework handed in as links of the school's allow-list, each submission a version
 * dated by the server, the last one to be corrected; idempotent by key; late refused or marked as
 * the activity says; a link that does not open flagged by the teacher, replaced within the delay
 * (SQL 082). A submission proves a link was handed in then, not that what it shows has not changed.
 */
final class HomeworkSubmissionController
{
    public function __construct(private CurrentSchool $school) {}

    /** The caller's own versions, newest first. */
    public function index(string $activity)
    {
        abort_unless(Str::isUuid($activity), 404);
        if (!DB::selectOne('SELECT 1 FROM school.my_activities() WHERE activity_id = ?', [$activity])) throw Refusal::of('lms_access_denied', 403);
        return ['data' => array_map(fn ($row) => [...(array) $row, 'links' => json_decode($row->links, true, 4, JSON_THROW_ON_ERROR)],
            DB::select('SELECT * FROM school.my_submissions(?)', [$activity]))];
    }

    /** One to five links, the acknowledgement being the version and its server time. */
    public function store(Request $request, string $activity)
    {
        abort_unless(Str::isUuid($activity), 404);
        $data = $request->validate(['idempotency_key' => 'required|uuid', 'links' => 'required|array|min:1|max:5', 'links.*' => 'string|max:2000']);
        $links = array_values(array_map('trim', $data['links']));
        DB::select('SELECT school.submit_homework(?, ?, ?::jsonb)', [$activity, $data['idempotency_key'], json_encode($links, JSON_THROW_ON_ERROR)]);
        return response()->json($this->index($activity), 201);
    }

    /** Every version handed in, for the teacher of the group's subject. */
    public function forActivity(string $activity)
    {
        abort_unless(Str::isUuid($activity), 404);
        abort_unless(DB::selectOne('SELECT 1 FROM school.activity WHERE tenant_id = ? AND id = ?', [$this->school->id(), $activity]), 404);
        return ['data' => array_map(fn ($row) => [...(array) $row, 'links' => json_decode($row->links, true, 4, JSON_THROW_ON_ERROR)],
            DB::select('SELECT x.id, x.student_id, x.number, x.links, x.submitted_at, x.late, x.status, x.flag_reason, x.replace_until, x.version,
                s.matricule, s.last_name_ar, s.first_name_ar, s.last_name_lat, s.first_name_lat
                FROM school.homework_submission x LEFT JOIN school.student s ON s.tenant_id = x.tenant_id AND s.id = x.student_id
                WHERE x.tenant_id = ? AND x.activity_id = ? ORDER BY s.last_name_lat, s.first_name_lat, x.number DESC', [$this->school->id(), $activity]))];
    }

    /** The version to correct does not open: kept, marked so, and another asked for within the delay. */
    public function flag(Request $request, string $submission)
    {
        abort_unless(Str::isUuid($submission), 404);
        $data = $request->validate(['reason' => 'required|string|min:3|max:500']);
        $until = DB::selectOne('SELECT school.flag_submission(?, ?) AS u', [$submission, $data['reason']])->u;
        return ['data' => ['id' => $submission, 'replace_until' => $until]];
    }
}
