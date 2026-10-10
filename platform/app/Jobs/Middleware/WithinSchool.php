<?php

namespace App\Jobs\Middleware;

use App\Exceptions\SchoolAccessRevoked;
use App\Support\CurrentSchool;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Runs a background job in the school it was dispatched from (ARC-06, FR-JOB-01): one
 * transaction on the restricted connection, and a fresh check that the school is open and
 * the user still an active member. Between dispatch and execution, either may have changed.
 */
final class WithinSchool
{
    public function handle(object $job, Closure $next): void
    {
        DB::transaction(function () use ($job, $next) {
            $school = app(CurrentSchool::class);
            $school->enter($job->tenantId, $job->userId, $job->requestId);
            $state = DB::selectOne('SELECT platform.tenant_mode(?::uuid) AS mode, school.allowed(?::uuid) AS allowed', [$job->tenantId, $job->tenantId]);
            if (!$state->allowed) throw new SchoolAccessRevoked();
            $school->setMode($state->mode);
            $next($job);
        });
    }
}
