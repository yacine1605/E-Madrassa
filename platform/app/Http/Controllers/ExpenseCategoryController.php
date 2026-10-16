<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesLabels;
use App\Support\CurrentSchool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FR-DEP-01: the school's expense categories (« paramétrables »), given with the publisher's six;
 * read with the expenses, named by whoever validates them for the whole school. A code never
 * changes; a category is archived, never deleted, and its expenses keep it; `salaries` is the
 * payroll's and stays (SQL 055).
 */
final class ExpenseCategoryController
{
    use HandlesLabels;

    private const SELECT = "SELECT id, code, labels, needs_label, sort_order, version, archived_at, school.expense_category_system(code) AS system,
        (SELECT count(*) FROM school.expense e WHERE e.tenant_id = expense_category.tenant_id AND e.category_id = expense_category.id) AS expenses
        FROM school.expense_category";

    public function __construct(private CurrentSchool $school) {}

    public function index(Request $request)
    {
        $request->validate(['include_archived' => 'boolean']);
        $sql = self::SELECT.' WHERE tenant_id = ?'.($request->boolean('include_archived') ? '' : ' AND archived_at IS NULL');
        return ['data' => array_map(fn ($row) => $this->decoded($row), DB::select($sql.' ORDER BY sort_order, code', [$this->school->id()]))];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->labelRules() + ['code' => ['required', 'string', 'regex:/^[a-z0-9_]{1,30}$/'],
            'needs_label' => 'boolean', 'sort_order' => 'nullable|integer|min:0|max:100000']);
        $id = DB::selectOne('INSERT INTO school.expense_category (tenant_id, code, labels, needs_label, sort_order) VALUES (?, ?, ?::jsonb, ?, ?) RETURNING id',
            [$this->school->id(), $data['code'], $this->labels($data['labels']), (bool) ($data['needs_label'] ?? false), $data['sort_order'] ?? 0])->id;
        return response()->json(['data' => $this->find($id)], 201);
    }

    public function update(Request $request, string $category)
    {
        $data = $request->validate($this->labelRules() + ['needs_label' => 'boolean', 'sort_order' => 'required|integer|min:0|max:100000',
            'version' => 'required|integer|min:1']);
        $row = DB::selectOne('UPDATE school.expense_category SET labels = ?::jsonb, needs_label = ?, sort_order = ?, version = ? WHERE tenant_id = ? AND id = ? RETURNING id',
            [$this->labels($data['labels']), (bool) ($data['needs_label'] ?? false), $data['sort_order'], $data['version'], $this->school->id(), $this->uuid($category)]);
        abort_unless($row, 404);
        return ['data' => $this->find($row->id)];
    }

    /** No longer offered to new expenses; those that have it keep it. */
    public function archive(Request $request, string $category)
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:1000']);
        DB::select("SELECT set_config('app.reason', ?, true)", [$data['reason']]);
        $row = DB::selectOne('UPDATE school.expense_category SET archived_at = now() WHERE tenant_id = ? AND id = ? AND archived_at IS NULL RETURNING id',
            [$this->school->id(), $this->uuid($category)]);
        abort_unless($row, 404);
        return ['data' => $this->find($row->id)];
    }

    /** The publisher's categories the school lacks, given back: a school not in use when it received the module had none (SQL 055). */
    public function defaults()
    {
        $added = DB::affectingStatement('INSERT INTO school.expense_category (tenant_id, code, labels, needs_label, sort_order)
            SELECT ?, d.code, d.labels, d.needs_label, d.sort_order FROM platform.expense_category_default d
            WHERE NOT EXISTS (SELECT 1 FROM school.expense_category x WHERE x.tenant_id = ? AND x.code = d.code)
            ORDER BY d.sort_order', [$this->school->id(), $this->school->id()]);
        return ['data' => ['added' => $added]];
    }

    /** How many of the publisher's categories the school lacks. */
    public function missingDefaults(): int
    {
        return (int) DB::selectOne('SELECT count(*) AS n FROM platform.expense_category_default d
            WHERE NOT EXISTS (SELECT 1 FROM school.expense_category x WHERE x.tenant_id = ? AND x.code = d.code)', [$this->school->id()])->n;
    }

    private function find(string $id): array
    {
        return $this->decoded(DB::selectOne(self::SELECT.' WHERE tenant_id = ? AND id = ?', [$this->school->id(), $id]));
    }
}
