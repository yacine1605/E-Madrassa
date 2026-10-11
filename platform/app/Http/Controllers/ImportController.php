<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Imports\DependentOperation;
use App\Support\Imports;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Imports from Excel or CSV files (FR-IMP-01): `import.job.create` and the permission that
 * creates the records on screen. Template, upload and preview, confirmation, rejection
 * report, undo.
 */
final class ImportController
{
    public function __construct(private Imports $imports, private Permissions $permissions) {}

    public function template(Request $request, string $kind)
    {
        abort_unless(array_key_exists($kind, config('platform.imports')), 404);
        $format = $request->query('format') === 'xlsx' ? 'xlsx' : 'csv';
        return response($this->imports->template($kind, $format), 200, [
            'Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="modele-'.Str::slug($kind).'.'.$format.'"']);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['kind' => ['required', Rule::in(array_keys(config('platform.imports')))], 'file' => 'required|file|max:20480',
            'options' => 'nullable|array', 'options.invite' => 'boolean']);
        $this->allowed($data['kind']);
        return response()->json(['data' => $this->imports->start($request->file('file'), $data['kind'], $data['options'] ?? [])], 202);
    }

    /** The latest imports, each with its rows counted: in all, valid or applied, rejected. */
    public function index()
    {
        return ['data' => DB::select('SELECT '.Imports::BATCH_COLUMNS.", coalesce(c.total, 0) AS rows_total, coalesce(c.rejected, 0) AS rows_rejected,
            coalesce(c.total - c.rejected, 0) AS rows_valid FROM school.import_batch b
            LEFT JOIN (SELECT batch_id, count(*) AS total, count(*) FILTER (WHERE outcome = 'rejected') AS rejected FROM school.import_row GROUP BY batch_id) c
              ON c.batch_id = b.id ORDER BY created_at DESC LIMIT 100")];
    }

    public function show(string $batch)
    {
        return ['data' => $this->imports->summary($this->uuid($batch))];
    }

    /** The rows, by outcome (rejected ones with their cause), by pages of 100. */
    public function rows(Request $request, string $batch)
    {
        $data = $request->validate(['outcome' => ['nullable', Rule::in(['valid', 'rejected', 'created', 'updated', 'unchanged', 'undone'])],
            'page' => 'nullable|integer|min:1']);
        $this->imports->summary($this->uuid($batch));
        $sql = 'SELECT line, external_ref, data, outcome, action, error_code, error_message FROM school.import_row WHERE batch_id = ?';
        $bindings = [$batch];
        if (isset($data['outcome'])) { $sql .= ' AND outcome = ?'; $bindings[] = $data['outcome']; }
        $rows = DB::select($sql.' ORDER BY line LIMIT 100 OFFSET '.(100 * (($data['page'] ?? 1) - 1)), $bindings);
        return ['data' => array_map(fn ($row) => [...(array) $row, 'data' => json_decode($row->data, true)], $rows)];
    }

    public function report(string $batch)
    {
        $this->imports->summary($this->uuid($batch));
        return response($this->imports->report($batch), 200, ['Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="rejets-'.$batch.'.csv"']);
    }

    public function confirm(string $batch)
    {
        $this->allowed($this->imports->summary($this->uuid($batch))['kind']);
        return response()->json(['data' => $this->imports->confirm($batch)], 202);
    }

    /** All or nothing: a refusal leaves the import as it was. */
    public function rollback(string $batch)
    {
        $this->allowed($this->imports->summary($this->uuid($batch))['kind']);
        try {
            return ['data' => $this->imports->undo($batch)];
        } catch (DependentOperation $e) {
            // Rendered by the handler: the rows already undone are rolled back with the rest.
            throw Refusal::of('dependent_operation', 409, ['line' => $e->importLine, 'reason' => $e->getMessage()], ['line' => $e->importLine], 'import_line');
        }
    }

    /** The permission that creates these records on screen (P5). */
    private function allowed(string $kind): void
    {
        if (!$this->permissions->can($this->imports->importer($kind)->permission())) throw Refusal::permissionDenied();
    }

    private function uuid(string $id): string
    {
        abort_unless(Str::isUuid($id), 404);
        return $id;
    }
}
