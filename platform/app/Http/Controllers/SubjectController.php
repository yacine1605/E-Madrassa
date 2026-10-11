<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Http\Controllers\Concerns\HandlesLabels;
use App\Support\CurrentSchool;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The subject (school) or module (training centre) catalog (FR-REF-03): its kind follows the
 * school's profile. Deleted while never used, archived otherwise.
 */
final class SubjectController
{
    use HandlesLabels;

    private const SELECT = 'SELECT id, code, kind, labels, description, sort_order, version, archived_at FROM school.subject';

    public function __construct(private CurrentSchool $school) {}

    public function index(Request $request)
    {
        $sql = self::SELECT.' WHERE tenant_id = ?'.($request->boolean('include_archived') ? '' : ' AND archived_at IS NULL');
        return ['data' => array_map(fn ($row) => $this->decoded($row), DB::select($sql.' ORDER BY sort_order, code', [$this->school->id()]))];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->labelRules() + [
            'code' => ['required', 'string', 'regex:/^[A-Z0-9_]{1,20}$/'],
            'description' => 'nullable|string|max:2000',
            'sort_order' => 'nullable|integer|min:0|max:100000',
        ]);
        $kind = DB::selectOne("SELECT coalesce(p.subject_kind, 'subject') AS kind FROM platform.tenant t
            LEFT JOIN platform.profile p ON p.code = t.profile_code AND p.version = t.profile_version WHERE t.id = ?", [$this->school->id()])->kind;
        $id = DB::selectOne('INSERT INTO school.subject (tenant_id, code, kind, labels, description, sort_order) VALUES (?, ?, ?, ?::jsonb, ?, ?) RETURNING id',
            [$this->school->id(), $data['code'], $kind, $this->labels($data['labels']), $data['description'] ?? null, $data['sort_order'] ?? 0])->id;
        return response()->json(['data' => $this->find($id)], 201);
    }

    public function update(Request $request, string $subject)
    {
        $data = $request->validate($this->labelRules() + ['description' => 'nullable|string|max:2000',
            'sort_order' => 'required|integer|min:0|max:100000', 'version' => 'required|integer|min:1']);
        $row = DB::selectOne('UPDATE school.subject SET labels = ?::jsonb, description = ?, sort_order = ?, version = ? WHERE tenant_id = ? AND id = ? RETURNING id',
            [$this->labels($data['labels']), $data['description'] ?? null, $data['sort_order'], $data['version'], $this->school->id(), $this->uuid($subject)]);
        abort_unless($row, 404);
        return ['data' => $this->find($row->id)];
    }

    public function archive(Request $request, string $subject)
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:1000']);
        DB::select("SELECT set_config('app.reason', ?, true)", [$data['reason']]);
        $row = DB::selectOne('UPDATE school.subject SET archived_at = now() WHERE tenant_id = ? AND id = ? AND archived_at IS NULL RETURNING id',
            [$this->school->id(), $this->uuid($subject)]);
        abort_unless($row, 404);
        return ['data' => $this->find($row->id)];
    }

    /** FR-REF-03 AC1, AC2: only a subject nothing refers to; otherwise the answer proposes archiving. */
    public function destroy(string $subject)
    {
        try {
            // In a savepoint, so that a refused deletion leaves the request's transaction usable.
            $deleted = DB::transaction(fn () => DB::delete('DELETE FROM school.subject WHERE tenant_id = ? AND id = ?', [$this->school->id(), $this->uuid($subject)]));
        } catch (QueryException $e) {
            // A later table's reference (program, qualification, session, grade).
            if ((string) $e->getCode() !== '23503') throw $e;
            return Refusal::response('subject_in_use', 409);
        }
        abort_unless($deleted, 404);
        return response()->noContent();
    }

    private function find(string $id): array
    {
        return $this->decoded(DB::selectOne(self::SELECT.' WHERE tenant_id = ? AND id = ?', [$this->school->id(), $id]));
    }
}
