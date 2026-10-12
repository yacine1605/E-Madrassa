<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesLabels;
use App\Support\AcademicRecords;
use App\Support\CurrentSchool;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class EnrollmentController
{
    use HandlesLabels;

    private const COLUMNS = "id, student_id, program_id, year_id, status, status_reason, agreed_price, discount_total,
        price_version, terms_snapshot || jsonb_build_object('base_price', agreed_price::text) AS terms_snapshot,
        validated_by, validated_at, transferred_from, version, archived_at, created_at, updated_at";

    public function __construct(private CurrentSchool $school, private AcademicRecords $records) {}

    public function index(Request $request)
    {
        return $this->records->listing($request, 'SELECT '.self::COLUMNS.' FROM school.enrollment',
            ['student_id' => 'nullable|uuid', 'program_id' => 'nullable|uuid', 'year_id' => 'nullable|uuid',
                'status' => 'nullable|in:pending,validated,suspended,cancelled'], [], 'created_at DESC, id', fn ($row) => $this->present($row));
    }

    public function show(string $enrollment) { return ['data' => $this->present($this->find($enrollment))]; }

    public function store(Request $request)
    {
        $this->records->global('enrollment.enrollment.create');
        $data = $request->validate(['student_id' => 'required|uuid', 'program_id' => 'required|uuid', 'idempotency_key' => 'required|uuid',
            ...array_fill_keys(['tenant_id', 'year_id', 'status', 'agreed_price', 'discount_total', 'price_version', 'terms_snapshot', 'transferred_from', 'financial_status'], 'prohibited')]);
        $this->records->find('student', $data['student_id']);
        $this->records->find('program', $data['program_id']);
        $id = DB::selectOne('SELECT school.enroll(?, ?, ?) AS id', [$data['student_id'], $data['program_id'], $data['idempotency_key']])->id;
        // The same successful request, including a retry, returns the same resource.
        return response()->json($this->show($id), 201);
    }

    public function validateEnrollment(Request $request, string $enrollment) { return $this->status($request, $enrollment, 'validated', 'enrollment.enrollment.validate'); }
    public function suspend(Request $request, string $enrollment) { return $this->status($request, $enrollment, 'suspended', 'enrollment.enrollment.suspend'); }
    public function cancel(Request $request, string $enrollment) { return $this->status($request, $enrollment, 'cancelled', 'enrollment.enrollment.cancel'); }

    private function status(Request $request, string $id, string $status, string $permission): array
    {
        $row = $this->find($id, true);
        $this->records->action($permission, 'enrollment', $row->id);
        $data = $request->validate(['version' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:500']);
        $this->records->version($row, (int) $data['version']);
        $this->records->reason($data['reason']);
        $changed = DB::selectOne('UPDATE school.enrollment SET status = ?, status_reason = ?, version = ? WHERE tenant_id = ? AND id = ? RETURNING '.self::COLUMNS,
            [$status, $status === 'validated' ? null : $data['reason'], $data['version'], $this->school->id(), $row->id]);
        abort_unless($changed, 404);
        // Cancellation may remove the caller's group-based read scope; return the authorised
        // mutation itself rather than re-reading after its group memberships have ended.
        return ['data' => $this->present($changed)];
    }

    public function history(Request $request, string $enrollment)
    {
        $this->find($enrollment);
        $request->validate(Paging::RULES);
        $request->merge(['paginate' => true]);
        return Paging::select($request, 'SELECT id, from_status, to_status, reason, changed_at, changed_by FROM school.enrollment_status_history WHERE tenant_id = ? AND enrollment_id = ?',
            [$this->school->id(), $enrollment], 'changed_at, id');
    }

    private function find(string $id, bool $lock = false): object { return $this->records->find('enrollment', $id, $lock, self::COLUMNS); }
    private function present(object $row): array { return $this->decoded($row, ['terms_snapshot']); }
}
