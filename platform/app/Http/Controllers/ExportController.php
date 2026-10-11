<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequirePermission;
use App\Support\CurrentSchool;
use App\Support\Exporter;
use App\Support\Permissions;
use App\Support\Tasks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Exports (FR-IMP-02): `export.job.create` and the permission that reads the data on screen.
 * Up to 2 000 rows the file is ready at once; beyond, a background job makes it and notifies.
 */
final class ExportController
{
    public function __construct(private Exporter $exporter, private Permissions $permissions, private CurrentSchool $school) {}

    public function store(Request $request, Tasks $tasks)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(config('platform.exports')))],
            'format' => ['required', Rule::in(['csv', 'xlsx', 'pdf'])],
            'locale' => ['nullable', Rule::in(['fr', 'ar', 'en'])],
            'filters' => 'nullable|array',
            'idempotency_key' => 'nullable|string|max:100',
        ]);
        $dataset = $this->exporter->dataset($data['kind']);
        // The same permission as the screen that shows these rows (P5).
        if (!$this->permissions->can($dataset->permission())) return RequirePermission::denied();
        $filters = Validator::make($data['filters'] ?? [], $dataset->filters())->validate();
        $locale = $data['locale'] ?? DB::selectOne('SELECT default_locale FROM platform.tenant WHERE id = ?', [$this->school->id()])->default_locale;
        if ($dataset->count($filters) > Exporter::backgroundRows()) {
            $job = $tasks->start('exports.generate', $data['idempotency_key'] ?? (string) Str::uuid(),
                ['kind' => $data['kind'], 'format' => $data['format'], 'filters' => $filters, 'locale' => $locale]);
            return response()->json(['data' => ['job' => $job]], 202);
        }
        return response()->json(['data' => $this->exporter->run($data['kind'], $data['format'], $filters, $locale)], 201);
    }

    /** The user's exports; whoever reads the journal sees everyone's. */
    public function index()
    {
        return ['data' => array_map(fn ($row) => [...(array) $row, 'filters' => json_decode($row->filters, true)],
            DB::select('SELECT l.id, l.user_id, u.name AS author, l.kind, l.format, l.filters, l.row_count, l.file_id, f.purged_at IS NOT NULL AS purged, l.created_at
                FROM school.export_log l JOIN public.users u ON u.id = l.user_id
                LEFT JOIN school.file_object f ON f.tenant_id = l.tenant_id AND f.id = l.file_id ORDER BY l.created_at DESC LIMIT 100'))];
    }
}
