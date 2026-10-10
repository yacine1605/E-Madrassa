<?php

namespace App\Jobs\Concerns;

use App\Jobs\Middleware\WithinSchool;
use App\Support\CurrentSchool;
use Illuminate\Support\Str;

/**
 * For jobs dispatched from a school request: the job records which school, which user and
 * which request it belongs to, and WithinSchool restores and re-checks that context.
 */
trait RunsInSchool
{
    public string $tenantId;
    public int $userId;
    public string $requestId;

    protected function inCurrentSchool(): void
    {
        $this->tenantId = app(CurrentSchool::class)->id();
        $this->userId = (int) auth()->id();
        $this->requestId = (string) (request()->headers->get('X-Request-Id') ?? Str::uuid());
    }

    public function middleware(): array
    {
        return [new WithinSchool()];
    }
}
