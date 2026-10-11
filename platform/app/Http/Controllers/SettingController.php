<?php

namespace App\Http\Controllers;

use App\Support\CurrentSchool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** School settings (FR-REF-06 base): the catalog's keys, with the school's value or the default. Bounds are the database's. */
final class SettingController
{
    private const SELECT = 'SELECT d.key, d.value_type, d.min_value, d.max_value, array_to_json(d.allowed_values) AS allowed_values, d.labels,
        d.default_value, school.setting_value(d.key) AS value, s.version FROM platform.setting_definition d
        LEFT JOIN school.setting s ON s.tenant_id = ? AND s.key = d.key';

    public function __construct(private CurrentSchool $school) {}

    public function index()
    {
        return ['data' => array_map(fn ($row) => $this->present($row), DB::select(self::SELECT.' ORDER BY d.key', [$this->school->id()]))];
    }

    public function update(Request $request, string $key)
    {
        abort_unless(DB::selectOne('SELECT 1 AS found FROM platform.setting_definition WHERE key = ?', [$key]), 404);
        $data = $request->validate(['value' => 'present']);
        DB::insert('INSERT INTO school.setting (tenant_id, key, value) VALUES (?, ?, ?::jsonb)
            ON CONFLICT (tenant_id, key) DO UPDATE SET value = EXCLUDED.value',
            [$this->school->id(), $key, json_encode($data['value'], JSON_THROW_ON_ERROR)]);
        return ['data' => $this->present(DB::selectOne(self::SELECT.' WHERE d.key = ?', [$this->school->id(), $key]))];
    }

    private function present(object $row): array
    {
        return [...(array) $row,
            'labels' => json_decode($row->labels, true, 4, JSON_THROW_ON_ERROR),
            'allowed_values' => $row->allowed_values === null ? null : json_decode($row->allowed_values, true, 2, JSON_THROW_ON_ERROR),
            'default_value' => json_decode($row->default_value, false, 4, JSON_THROW_ON_ERROR),
            'value' => json_decode($row->value, false, 4, JSON_THROW_ON_ERROR)];
    }
}
