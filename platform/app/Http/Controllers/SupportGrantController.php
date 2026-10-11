<?php

namespace App\Http\Controllers;

use App\Support\CurrentSchool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The school's consents to the publisher's support (FR-PLT-04): given for 72 hours at most,
 * sensitive data only by a distinct consent, revoked at any time; all of them listed with the
 * number of consultations each one saw (AC3).
 */
final class SupportGrantController
{
    private const SELECT = "SELECT g.id, g.include_sensitive, g.reason, g.starts_at, g.expires_at, g.revoked_at, g.created_by, u.name AS granted_by,
        (SELECT count(*) FROM school.audit_log a WHERE a.tenant_id = g.tenant_id AND a.object_id = g.id AND a.action = 'SUPPORT_READ') AS consultations
        FROM school.support_grant g LEFT JOIN public.users u ON u.id = g.created_by";

    public function __construct(private CurrentSchool $school) {}

    public function index()
    {
        return ['data' => DB::select(self::SELECT.' ORDER BY g.starts_at DESC')];
    }

    public function store(Request $request)
    {
        $data = $request->validate(['hours' => 'required|integer|min:1|max:72', 'include_sensitive' => 'boolean', 'reason' => 'required|string|min:3|max:1000']);
        $id = DB::selectOne("INSERT INTO school.support_grant (tenant_id, reason, include_sensitive, expires_at) VALUES (?, ?, ?, now() + make_interval(hours => ?)) RETURNING id",
            [$this->school->id(), $data['reason'], $request->boolean('include_sensitive'), $data['hours']])->id;
        return response()->json(['data' => DB::selectOne(self::SELECT.' WHERE g.id = ?', [$id])], 201);
    }

    public function revoke(string $grant)
    {
        abort_unless(Str::isUuid($grant), 404);
        abort_unless(DB::update('UPDATE school.support_grant SET revoked_at = now() WHERE id = ? AND revoked_at IS NULL', [$grant]), 404);
        return ['data' => DB::selectOne(self::SELECT.' WHERE g.id = ?', [$grant])];
    }
}
