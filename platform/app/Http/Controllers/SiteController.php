<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Support\CurrentSchool;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sites of the current school (ARC-03, FR-SLL-01 base). Every member with access to the
 * referential reads them; `site.site.manage` changes them, within its scope (FR-IAM-04).
 * Row-level security is the second barrier.
 */
final class SiteController
{
    private const COLUMNS = 'id, code, labels, is_default, version, archived_at, created_at, updated_at';

    public function __construct(private CurrentSchool $school, private Permissions $permissions) {}

    public function index(Request $request)
    {
        $archived = $request->boolean('include_archived');
        $rows = DB::select('SELECT '.self::COLUMNS.' FROM school.site WHERE tenant_id = ?'.($archived ? '' : ' AND archived_at IS NULL').' ORDER BY code',
            [$this->school->id()]);
        return ['data' => array_map(fn ($row) => $this->present($row), $rows)];
    }

    public function store(Request $request)
    {
        // A new site is outside any site scope: only a grant for the whole school creates one.
        if (!$this->permissions->everywhere('site.site.manage')) throw Refusal::permissionDenied();
        $data = $request->validate($this->labelRules() + ['code' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,40}$/']]);
        $row = DB::selectOne('INSERT INTO school.site (tenant_id, code, labels) VALUES (?, ?, ?::jsonb) RETURNING '.self::COLUMNS,
            [$this->school->id(), $data['code'], json_encode($this->labels($data), JSON_THROW_ON_ERROR)]);
        return response()->json(['data' => $this->present($row)], 201);
    }

    public function update(Request $request, string $site)
    {
        $this->inScope($site);
        $data = $request->validate($this->labelRules() + ['version' => 'required|integer|min:1']);
        // The version read by the client goes back to the database, which refuses a stale one.
        $row = DB::selectOne('UPDATE school.site SET labels = ?::jsonb, version = ? WHERE tenant_id = ? AND id = ? RETURNING '.self::COLUMNS,
            [json_encode($this->labels($data), JSON_THROW_ON_ERROR), $data['version'], $this->school->id(), $this->uuid($site)]);
        abort_unless($row, 404);
        return ['data' => $this->present($row)];
    }

    /** BR-02: archived, never deleted. The reason goes to the audit journal. */
    public function archive(Request $request, string $site)
    {
        $this->inScope($site);
        $data = $request->validate(['reason' => 'required|string|min:3|max:1000']);
        DB::select("SELECT set_config('app.reason', ?, true)", [$data['reason']]);
        $row = DB::selectOne('UPDATE school.site SET archived_at = now() WHERE tenant_id = ? AND id = ? AND archived_at IS NULL RETURNING '.self::COLUMNS,
            [$this->school->id(), $this->uuid($site)]);
        abort_unless($row, 404);
        return ['data' => $this->present($row)];
    }

    private function inScope(string $site): void
    {
        $sites = $this->permissions->sites('site.site.manage');
        if ($sites !== null && !in_array($this->uuid($site), $sites, true)) throw Refusal::permissionDenied();
    }

    private function labelRules(): array
    {
        return ['labels' => 'required|array', 'labels.fr' => 'required|string|max:150',
            'labels.ar' => 'nullable|string|max:150', 'labels.en' => 'nullable|string|max:150'];
    }

    private function labels(array $data): array
    {
        return array_filter(array_intersect_key($data['labels'], ['fr' => 1, 'ar' => 1, 'en' => 1]), fn ($label) => $label !== null && $label !== '');
    }

    private function uuid(string $id): string
    {
        abort_unless(Str::isUuid($id), 404);
        return $id;
    }

    private function present(object $row): array
    {
        return [...(array) $row, 'labels' => json_decode($row->labels, true, 512, JSON_THROW_ON_ERROR)];
    }
}
