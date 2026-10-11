<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Support\SupportSessions;
use Illuminate\Http\Request;

/** An operator opens a support session on a school that consented (FR-PLT-04), and closes it. */
final class SupportSessionController
{
    public function open(Request $request, SupportSessions $sessions)
    {
        $data = $request->validate(['tenant_id' => 'required|uuid', 'grant_id' => 'required|uuid']);
        if (!$sessions->open($data['tenant_id'], $data['grant_id'])) {
            throw Refusal::of('support_not_granted', 403);
        }
        return response()->noContent();
    }

    public function close(SupportSessions $sessions)
    {
        $sessions->close();
        return response()->noContent();
    }
}
