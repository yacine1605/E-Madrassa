<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

/**
 * NFR-DATA-03: the school's retention policy, category by category (its settings `retention.*`),
 * and what the next daily run will do with it: counts only, never the records themselves.
 */
final class RetentionController
{
    public function show()
    {
        $durations = DB::select("SELECT d.key, (school.setting_value(d.key) #>> '{}')::integer AS value FROM platform.setting_definition d
            WHERE d.key LIKE 'retention.%' ORDER BY d.key");
        return ['data' => [
            'durations' => collect($durations)->mapWithKeys(fn ($row) => [substr($row->key, strlen('retention.')) => $row->value])->all(),
            'due' => collect(DB::select('SELECT category, due FROM school.retention_due()'))->mapWithKeys(fn ($row) => [$row->category => $row->due])->all(),
        ]];
    }
}
