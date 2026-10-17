<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Support\AcademicRecords;
use App\Support\CurrentSchool;
use App\Support\Paging;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FR-PLN-01: the school's calendar. The week's open days are a setting (`calendar.open_days`); dated
 * exceptions prevail over them, a site's over the whole school's: closures (public holiday,
 * vacation, other) and exceptional openings, from one day until another, with their reason, never
 * two of the same scope on a day; cancelled until their last day, never deleted (SQL 061).
 */
final class CalendarExceptionController
{
    public const KINDS = ['holiday', 'vacation', 'closure', 'opening'];
    private const COLUMNS = "id, site_id, kind, date_from, date_to, reason, status, cancelled_at, version, created_at, updated_at, date_to - date_from + 1 AS days,
        (SELECT u.name FROM public.users u WHERE u.id = calendar_exception.created_by) AS recorded_by_name";
    /** The longest stretch of days read at once. */
    private const MAX_DAYS = 92;

    public function __construct(private CurrentSchool $school, private AcademicRecords $records, private Permissions $permissions) {}

    /** Those to come and under way, the next first; with `include_past` and `include_cancelled`, the others too; `site_id` a site's and the whole school's. */
    public function index(Request $request)
    {
        $data = $request->validate(Paging::RULES + ['site_id' => 'nullable|uuid', 'include_past' => 'boolean', 'include_cancelled' => 'boolean']);
        $sql = 'SELECT '.self::COLUMNS.' FROM school.calendar_exception WHERE tenant_id = ?';
        $bindings = [$this->school->id()];
        if (isset($data['site_id'])) { $sql .= ' AND (site_id IS NULL OR site_id = ?)'; $bindings[] = $data['site_id']; }
        if (!$request->boolean('include_past')) $sql .= ' AND date_to >= current_date';
        if (!$request->boolean('include_cancelled')) $sql .= " AND status = 'planned'";
        $request->merge(['paginate' => true]);
        return Paging::select($request, $sql, $bindings, 'date_from, created_at, id');
    }

    /**
     * Whether the school is open each day from one day until another (at most 92), on a site or for
     * the whole school, and the exception that decides it when one does.
     */
    public function days(Request $request)
    {
        $data = $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from', 'site_id' => 'nullable|uuid']);
        if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) >= self::MAX_DAYS) throw Refusal::of('calendar_range_too_long');
        $site = $data['site_id'] ?? null;
        $rows = DB::select("SELECT d::date::text AS day, extract(dow FROM d)::int AS weekday, school.day_open(d::date, ?::uuid) AS open,
              e.id AS exception_id, e.kind, e.reason, e.site_id
            FROM generate_series(?::date, ?::date, interval '1 day') d
            LEFT JOIN LATERAL (SELECT x.id, x.kind, x.reason, x.site_id FROM school.calendar_exception x WHERE x.tenant_id = ? AND x.status = 'planned'
              AND d::date BETWEEN x.date_from AND x.date_to AND (x.site_id IS NULL OR x.site_id = ?::uuid) ORDER BY x.site_id IS NULL LIMIT 1) e ON true
            ORDER BY d", [$site, $data['from'], $data['to'], $this->school->id(), $site]);
        return ['data' => array_map(fn ($row) => (array) $row, $rows)];
    }

    public function store(Request $request)
    {
        $data = $request->validate(['site_id' => 'nullable|uuid', 'kind' => 'required|in:'.implode(',', self::KINDS),
            'date_from' => 'required|date_format:Y-m-d', 'date_to' => 'required|date_format:Y-m-d|after_or_equal:date_from',
            'reason' => 'required|string|min:3|max:500', ...array_fill_keys(['tenant_id', 'status', 'cancelled_at'], 'prohibited')]);
        $this->inScope($data['site_id'] ?? null);
        $id = DB::table('school.calendar_exception')->insertGetId(['tenant_id' => $this->school->id(), 'site_id' => $data['site_id'] ?? null,
            'kind' => $data['kind'], 'date_from' => $data['date_from'], 'date_to' => $data['date_to'], 'reason' => $data['reason']], 'id');
        return response()->json(['data' => (array) $this->find($id)], 201);
    }

    /** Until its last day: the week's rule is back on its days; the journal keeps it. */
    public function cancel(Request $request, string $exception)
    {
        // The scope first: an exception of another site is not locked by whoever may not change it (its policy).
        $this->inScope($this->find($exception)->site_id);
        $row = $this->find($exception, true);
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $this->records->version($row, (int) $data['version']);
        DB::table('school.calendar_exception')->where('tenant_id', $this->school->id())->where('id', $row->id)
            ->update(['status' => 'cancelled', 'version' => $data['version']]);
        return ['data' => (array) $this->find($row->id)];
    }

    /** The whole school's calendar needs a school-wide grant; a site's, a grant on that site. */
    public function inScope(?string $site): void
    {
        $sites = $this->permissions->sites('schedule.calendar.manage');
        if ($sites !== null && ($site === null || !in_array($site, $sites, true))) throw Refusal::permissionDenied();
    }

    private function find(string $id, bool $lock = false): object { return $this->records->find('calendar_exception', $id, $lock, self::COLUMNS); }
}
