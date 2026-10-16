<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Support\AcademicRecords;
use App\Support\CurrentSchool;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FR-STF-04: a member's contract in dated versions: its type, its dates and the hours a week it
 * provides for, never an amount (AC1: pay is the remuneration rules', FR-REM-01). An amendment
 * ends the version in force the day before its own first day; a version's end only comes earlier;
 * one not begun yet is deleted. The member's state and the overlaps are the database's (SQL 035).
 */
final class ContractController
{
    // With where it stands today.
    private const COLUMNS = "id, staff_id, contract_type, reference, valid_from, valid_to, weekly_hours, observation, end_reason, version,
        created_at, updated_at, CASE WHEN valid_from > current_date THEN 'upcoming' WHEN valid_to < current_date THEN 'ended' ELSE 'in_force' END AS state";
    private const TERMS = ['contract_type', 'reference', 'valid_from', 'valid_to', 'weekly_hours', 'observation'];
    public const TYPES = ['permanent', 'fixed_term', 'hourly', 'service', 'internship', 'other'];

    public function __construct(private CurrentSchool $school, private AcademicRecords $records) {}

    /** The latest first. */
    public function index(Request $request, string $staff)
    {
        $this->records->find('staff', $staff, columns: 'id');
        $request->validate(Paging::RULES);
        $request->merge(['paginate' => true]);
        return Paging::select($request, 'SELECT '.self::COLUMNS.' FROM school.staff_contract WHERE tenant_id = ? AND staff_id = ?',
            [$this->school->id(), $staff], 'valid_from DESC, id');
    }

    /** From `valid_from` (today when absent); a fixed-term contract or an internship has its end. */
    public function store(Request $request, string $staff)
    {
        $row = $this->records->find('staff', $staff, columns: 'id');
        DB::select('SELECT school.require_staff(?, ?)', ['staff.contract.manage', $row->id]);
        $data = $request->validate([...$this->rules('required'), 'valid_from' => 'sometimes|nullable|date_format:Y-m-d',
            'valid_to' => 'sometimes|nullable|date_format:Y-m-d|after_or_equal:valid_from']);
        return response()->json(['data' => (array) $this->find($this->insert($row->id, $data))], 201);
    }

    /**
     * A new version from `valid_from`, after the first day of the one amended and not after its end:
     * that one ends the day before. What is not sent is kept: type, reference, hours, end.
     */
    public function amend(Request $request, string $contract)
    {
        $row = $this->find($contract, true);
        DB::select('SELECT school.require_staff(?, ?)', ['staff.contract.manage', $row->staff_id]);
        $data = $request->validate([...$this->rules('sometimes'), 'version' => 'required|integer|min:1',
            'valid_from' => ['required', 'date_format:Y-m-d', 'after:'.$row->valid_from, ...($row->valid_to ? ['before_or_equal:'.$row->valid_to] : [])],
            'valid_to' => 'sometimes|nullable|date_format:Y-m-d|after_or_equal:valid_from']);
        $this->records->version($row, (int) $data['version']);
        DB::table('school.staff_contract')->where('tenant_id', $this->school->id())->where('id', $row->id)
            ->update(['valid_to' => Carbon::parse($data['valid_from'])->subDay()->toDateString(), 'version' => $data['version']]);
        $next = [...array_intersect_key((array) $row, array_flip(self::TERMS)), 'observation' => null, ...array_intersect_key($data, array_flip(self::TERMS))];
        return response()->json(['data' => (array) $this->find($this->insert($row->staff_id, $next))], 201);
    }

    /** Ended on `valid_to`, earlier than planned, with its reason if told; never later. */
    public function end(Request $request, string $contract)
    {
        $row = $this->find($contract, true);
        DB::select('SELECT school.require_staff(?, ?)', ['staff.contract.manage', $row->staff_id]);
        $data = $request->validate(['valid_to' => 'required|date_format:Y-m-d', 'end_reason' => 'nullable|string|min:3|max:500',
            'version' => 'required|integer|min:1']);
        $this->records->version($row, (int) $data['version']);
        DB::table('school.staff_contract')->where('tenant_id', $this->school->id())->where('id', $row->id)
            ->update(['valid_to' => $data['valid_to'], 'end_reason' => $data['end_reason'] ?? null, 'version' => $data['version']]);
        return ['data' => (array) $this->find($row->id)];
    }

    /** A version not begun yet; the journal keeps its values. */
    public function destroy(Request $request, string $contract)
    {
        $row = $this->find($contract, true);
        DB::select('SELECT school.require_staff(?, ?)', ['staff.contract.manage', $row->staff_id]);
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $this->records->version($row, (int) $data['version']);
        if ($row->state !== 'upcoming') throw Refusal::of('contract_started');
        DB::table('school.staff_contract')->where('tenant_id', $this->school->id())->where('id', $row->id)->delete();
        return response()->noContent();
    }

    /** What a version says; never its member, its school or an end's reason, which `end` gives. */
    private function rules(string $type): array
    {
        return ['contract_type' => $type.'|in:'.implode(',', self::TYPES), 'reference' => 'nullable|string|max:60',
            'weekly_hours' => 'nullable|decimal:0,2|gt:0|max:168', 'observation' => 'nullable|string|max:1000',
            ...array_fill_keys(['tenant_id', 'staff_id', 'end_reason'], 'prohibited')];
    }

    private function insert(string $staff, array $data): string
    {
        return DB::table('school.staff_contract')->insertGetId(['tenant_id' => $this->school->id(), 'staff_id' => $staff,
            ...array_intersect_key($data, array_flip(self::TERMS))], 'id');
    }

    private function find(string $id, bool $lock = false): object { return $this->records->find('staff_contract', $id, $lock, self::COLUMNS); }
}
