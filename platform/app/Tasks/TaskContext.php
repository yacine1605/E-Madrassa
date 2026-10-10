<?php

namespace App\Tasks;

use App\Exceptions\SchoolAccessRevoked;
use App\Support\CurrentSchool;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * A background job's view of its school (FR-JOB-01, ARC-06). Each step runs in its own
 * transaction, in the job's school, after checking again that the school is open and the
 * user still a member: what a step wrote stays written if a later one fails, and progress is
 * visible as it goes. A cancellation asked for is honoured between steps.
 */
final class TaskContext
{
    public function __construct(public readonly string $tenantId, public readonly int $userId, public readonly string $jobId) {}

    /** Takes the job for this run; null when there is nothing to do (finished, cancelled, or awaiting a manual retry). */
    public function begin(): ?object
    {
        return DB::transaction(function () {
            $this->enter(true);
            $job = DB::selectOne('SELECT id, kind, status, input, cancel_requested_at FROM school.job WHERE id = ? FOR UPDATE', [$this->jobId]);
            if (!$job || in_array($job->status, ['succeeded', 'cancelled', 'failed'], true)) return null;
            if ($job->cancel_requested_at) {
                DB::update("UPDATE school.job SET status = 'cancelled' WHERE id = ?", [$this->jobId]);
                return null;
            }
            // A job found running was interrupted (crash, redelivery): it resumes; its steps are idempotent.
            if ($job->status === 'queued') DB::update("UPDATE school.job SET status = 'running' WHERE id = ?", [$this->jobId]);
            return $job;
        });
    }

    public function step(Closure $work): mixed
    {
        return DB::transaction(function () use ($work) {
            $this->enter(true);
            if (DB::selectOne('SELECT cancel_requested_at IS NOT NULL AS asked FROM school.job WHERE id = ?', [$this->jobId])->asked) {
                throw new TaskCancelled();
            }
            return $work();
        });
    }

    public function progress(int $percent): void
    {
        $this->write('UPDATE school.job SET progress = ? WHERE id = ?', [max(0, min(99, $percent)), $this->jobId]);
    }

    public function succeed(array $result): void
    {
        $this->write("UPDATE school.job SET status = 'succeeded', result = ?::jsonb WHERE id = ?", [json_encode($result, JSON_THROW_ON_ERROR), $this->jobId],
            'job.succeeded');
    }

    /** A refusal or a technical error, in words the user understands (never SQL, never internals). */
    public function fail(string $code, string $message): void
    {
        $this->write("UPDATE school.job SET status = 'failed', error_code = ?, error_message = ? WHERE id = ? AND status = 'running'",
            [$code, mb_substr($message, 0, 1000), $this->jobId], 'job.failed');
    }

    public function cancelled(): void
    {
        $this->write("UPDATE school.job SET status = 'cancelled' WHERE id = ? AND status = 'running'", [$this->jobId]);
    }

    /** Back to the queue for another attempt after a transient error. */
    public function requeue(string $message): void
    {
        $this->write("UPDATE school.job SET status = 'queued', error_message = ? WHERE id = ? AND status = 'running'", [mb_substr($message, 0, 1000), $this->jobId]);
    }

    /** Bookkeeping of the job itself: written even when the user has lost access meanwhile, to say so. */
    private function write(string $sql, array $bindings, ?string $notify = null): void
    {
        DB::transaction(function () use ($sql, $bindings, $notify) {
            $this->enter(false);
            if (DB::update($sql, $bindings) && $notify) {
                DB::select('SELECT school.notify(?, ARRAY[?]::bigint[], ?::jsonb)', [$notify, $this->userId, json_encode(['job_id' => $this->jobId])]);
            }
        });
    }

    private function enter(bool $check): void
    {
        $school = app(CurrentSchool::class);
        $school->enter($this->tenantId, $this->userId, 'job-'.$this->jobId);
        if (!$check) return;
        $state = DB::selectOne('SELECT platform.tenant_mode(?::uuid) AS mode, school.allowed(?::uuid) AS allowed', [$this->tenantId, $this->tenantId]);
        if (!$state->allowed) throw new SchoolAccessRevoked();
        $school->setMode($state->mode);
    }
}
