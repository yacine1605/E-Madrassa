<?php

namespace Tests\Support;

use App\Jobs\Concerns\RunsInSchool;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/** A background job that reads its school's data: it must see that school, and only it. */
final class CountSites implements ShouldQueue
{
    use Queueable, RunsInSchool;

    public static ?int $seen = null;

    public function __construct(string $tenant, int $user)
    {
        $this->tenantId = $tenant;
        $this->userId = $user;
        $this->requestId = 'job-test-0001';
    }

    public function handle(): void
    {
        self::$seen = (int) DB::selectOne('SELECT count(*) AS n FROM school.site')->n;
    }
}
