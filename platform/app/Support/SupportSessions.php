<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Support sessions (FR-PLT-04): the operator's session works in the school under one consent,
 * which the database checks at every request (operator, school, dates, not revoked).
 */
final class SupportSessions
{
    /** Opens the session if the consent is valid now for this operator. */
    public function open(string $tenant, string $grant): bool
    {
        $user = auth()->user();
        if (!$user?->isOperator()) return false;
        $valid = DB::transaction(function () use ($tenant, $grant, $user) {
            app(DatabaseContext::class)->enter($tenant, $user->id, $grant);
            return DB::selectOne('SELECT school.support_grant_in_use() AS g')->g === $grant;
        });
        if (!$valid) return false;
        session()->regenerate();
        session()->put(['school.tenant' => $tenant, 'support.grant' => $grant]);
        return true;
    }

    public function close(): void
    {
        session()->forget(['support.grant', 'school.tenant', 'school.idle_minutes', 'locale']);
        session()->regenerate();
    }
}
