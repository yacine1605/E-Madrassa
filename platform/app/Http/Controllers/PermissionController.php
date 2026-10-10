<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

final class PermissionController
{
    /** Annex A, for the modules the school has: the only permissions a role can receive (FR-IAM-03). Sensitive ones are flagged. */
    public function index()
    {
        $rows = DB::select('SELECT code, module_key, sensitive, labels FROM platform.permission
            WHERE module_key IN (SELECT school.my_modules()) ORDER BY module_key, code');
        return ['data' => array_map(fn ($row) => [...(array) $row, 'labels' => json_decode($row->labels, true, 4, JSON_THROW_ON_ERROR)], $rows)];
    }
}
