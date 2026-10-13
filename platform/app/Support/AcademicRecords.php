<?php

namespace App\Support;

use App\Exceptions\Refusal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Shared API boundaries; table names and filters are supplied only by controllers. */
final class AcademicRecords
{
    public function __construct(private CurrentSchool $school, private Permissions $permissions) {}

    public function find(string $table, string $id, bool $lock = false, string $columns = '*'): object
    {
        abort_unless(Str::isUuid($id), 404);
        $row = DB::selectOne('SELECT '.$columns.' FROM school.'.$table.' WHERE tenant_id = ? AND id = ?'.($lock ? ' FOR UPDATE' : ''), [$this->school->id(), $id]);
        abort_unless($row, 404);
        return $row;
    }

    /** Granted somewhere: what the new record reaches (its pupil's site, its group's) is the database's to check (SQL 029). */
    public function somewhere(string $permission): void
    {
        if (!$this->permissions->can($permission)) throw Refusal::permissionDenied();
    }

    public function global(string $permission): void
    {
        if (!$this->permissions->everywhere($permission)) throw Refusal::permissionDenied();
    }

    public function action(string $permission, string $entity, string $id): void
    {
        DB::select('SELECT school.require_core(?, ?, ?)', [$permission, $entity, $id]);
    }

    public function version(object $row, int $version): void
    {
        if ((int) $row->version !== $version) throw Refusal::of('stale_version');
    }

    public function reason(string $reason): void
    {
        DB::select("SELECT set_config('app.reason', ?, true)", [$reason]);
    }

    public function listing(Request $request, string $select, array $filters, array $search, string $order, callable $present): array
    {
        $data = $request->validate(Paging::RULES + $filters + ['q' => 'nullable|string|max:200', 'include_archived' => 'boolean']);
        $sql = $select.' WHERE tenant_id = ?';
        $bindings = [$this->school->id()];
        if (!$request->boolean('include_archived')) $sql .= ' AND archived_at IS NULL';
        foreach ($filters as $column => $_) {
            if (isset($data[$column])) { $sql .= ' AND '.$column.' = ?'; $bindings[] = $data[$column]; }
        }
        if ($search && isset($data['q']) && $data['q'] !== '') {
            $sql .= ' AND '.Paging::search($search);
            $bindings[] = $data['q'];
        }
        $request->merge(['paginate' => true]);
        return Paging::select($request, $sql, $bindings, $order, $present);
    }
}
