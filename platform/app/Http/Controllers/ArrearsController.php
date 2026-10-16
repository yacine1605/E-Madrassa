<?php

namespace App\Http\Controllers;

use App\Support\CurrentSchool;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FR-FIN-06: the open balances and the overdue instalments, by pupil, programme, group, as at a
 * date (today by default), for the enrolments whose payments the reader reads; with their totals,
 * which are what they owe less what they paid (AC1). Computed by SQL 052, never stored; nobody
 * is sanctioned by it.
 */
final class ArrearsController
{
    public const RULES = ['as_of' => 'nullable|date_format:Y-m-d', 'program' => 'nullable|uuid', 'group' => 'nullable|uuid', 'overdue_only' => 'nullable|boolean',
        'search' => 'nullable|string|max:100'];

    public function __construct(private CurrentSchool $school) {}

    public function index(Request $request)
    {
        $data = $request->validate(Paging::RULES + self::RULES);
        $request->merge(['paginate' => true]);
        [$sql, $bindings] = self::query($this->school->id(), $data);
        $page = Paging::select($request, $sql, $bindings, 'a.overdue_since NULLS LAST, a.balance DESC, s.matricule', fn ($row) => (array) $row);
        // AC1: what the listed enrolments owe, have paid and have left, whatever the page.
        $totals = DB::selectOne('SELECT coalesce(sum(due), 0)::numeric(14, 2)::text AS due, coalesce(sum(paid), 0)::numeric(14, 2)::text AS paid,
            coalesce(sum(balance), 0)::numeric(14, 2)::text AS balance, coalesce(sum(overdue), 0)::numeric(14, 2)::text AS overdue FROM ('.$sql.') listed', $bindings);
        $page['meta']['totals'] = ['due' => $totals->due, 'paid' => $totals->paid, 'balance' => $totals->balance, 'overdue' => $totals->overdue];
        $page['meta']['as_of'] = $data['as_of'] ?? now()->timezone('Africa/Algiers')->toDateString();
        return $page;
    }

    /** @return array{0: string, 1: array} the open balances asked for, as the screen and the export list them */
    public static function query(string $tenant, array $filters): array
    {
        $sql = "SELECT a.enrollment_id AS id, a.enrollment_id, a.due, a.paid, a.balance, a.overdue, a.overdue_since, a.next_due_date, e.status, e.terms_snapshot ->> 'code' AS program,
            s.matricule, s.last_name_ar, s.first_name_ar, s.last_name_lat, s.first_name_lat,
            (SELECT g.code FROM school.group_membership m JOIN school.study_group g ON g.tenant_id = m.tenant_id AND g.id = m.group_id
             WHERE m.tenant_id = e.tenant_id AND m.enrollment_id = e.id AND m.valid_to IS NULL ORDER BY m.is_main DESC, m.valid_from DESC LIMIT 1) AS group_code
            FROM school.arrears(?::date) a JOIN school.enrollment e ON e.tenant_id = ? AND e.id = a.enrollment_id
            LEFT JOIN school.student s ON s.tenant_id = e.tenant_id AND s.id = e.student_id
            WHERE a.balance > 0";
        $bindings = [$filters['as_of'] ?? now()->timezone('Africa/Algiers')->toDateString(), $tenant];
        if (!empty($filters['overdue_only'])) $sql .= ' AND a.overdue > 0';
        if (isset($filters['program'])) { $sql .= ' AND e.program_id = ?'; $bindings[] = $filters['program']; }
        if (isset($filters['group'])) {
            $sql .= ' AND EXISTS (SELECT 1 FROM school.group_membership m WHERE m.tenant_id = e.tenant_id AND m.enrollment_id = e.id AND m.group_id = ? AND m.valid_to IS NULL)';
            $bindings[] = $filters['group'];
        }
        if (isset($filters['search']) && trim($filters['search']) !== '') {
            $sql .= ' AND '.Paging::search(['s.matricule', 's.last_name_lat', 's.first_name_lat', 's.last_name_ar', 's.first_name_ar']);
            $bindings[] = $filters['search'];
        }
        return [$sql, $bindings];
    }
}
