<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The school's journal (FR-AUD-01), read-only: no route changes an entry (AC2). Row-level
 * security shows each reader the domains they may read; filters by person, object and period,
 * newest first, by pages that hold even while entries are added.
 */
final class AuditController
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'actor_id' => 'nullable|integer',
            'object_type' => 'nullable|string|max:40',
            'object_id' => 'nullable|uuid',
            'action' => 'nullable|string|max:40',
            'domain' => 'nullable|string|max:40',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'before' => 'nullable|string|max:100',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);
        $where = [];
        $bindings = [];
        foreach (['actor_id' => 'a.actor_id = ?', 'object_type' => 'a.object_type = ?', 'object_id' => 'a.object_id = ?', 'action' => 'a.action = ?',
                  'domain' => 'd.domain = ?', 'from' => 'a.occurred_at >= ?::date', 'to' => "a.occurred_at < ?::date + interval '1 day'"] as $field => $condition) {
            if (isset($data[$field])) { $where[] = $condition; $bindings[] = $data[$field]; }
        }
        // The cursor is the last entry of the previous page: its time and identifier.
        if (isset($data['before']) && preg_match('/^(.+)\|([0-9a-f-]{36})$/', $data['before'], $cursor)) {
            $where[] = '(a.occurred_at, a.id) < (?::timestamptz, ?::uuid)';
            array_push($bindings, $cursor[1], $cursor[2]);
        }
        $limit = $data['limit'] ?? 50;
        $rows = DB::select('SELECT a.id, a.occurred_at, a.actor_id, u.name AS actor_name, a.action, a.object_type, d.domain, a.object_id,
                a.changed_fields, a.before, a.after, a.reason, a.request_id, a.ip, a.linked_operation_id
            FROM school.audit_log a JOIN platform.audit_domain d ON d.object_type = a.object_type LEFT JOIN public.users u ON u.id = a.actor_id'
            .($where ? ' WHERE '.implode(' AND ', $where) : '').' ORDER BY a.occurred_at DESC, a.id DESC LIMIT '.($limit + 1), $bindings);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = end($rows);
        return [
            'data' => array_map(fn ($row) => [...(array) $row,
                'changed_fields' => json_decode($row->changed_fields, true),
                'before' => $row->before === null ? null : json_decode($row->before, true),
                'after' => $row->after === null ? null : json_decode($row->after, true)], $rows),
            'next' => $more && $last ? $last->occurred_at.'|'.$last->id : null,
        ];
    }
}
