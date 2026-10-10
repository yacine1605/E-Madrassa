<?php

namespace App\Support;

use App\Jobs\RunTask;
use Illuminate\Support\Facades\DB;

/** Starts school jobs (FR-JOB-01): once per idempotency key, queued after the request commits. */
final class Tasks
{
    public const COLUMNS = 'id, kind, status, progress, result, error_code, error_message, attempts, cancel_requested_at, started_at, finished_at, created_at';

    public function __construct(private CurrentSchool $school) {}

    /** The job of this key: started now, or the one already started (BR-05). */
    public function start(string $kind, string $key, array $input): array
    {
        $user = (int) auth()->id();
        $created = DB::selectOne('INSERT INTO school.job (tenant_id, user_id, kind, idempotency_key, input) VALUES (?, ?, ?, ?, ?::jsonb)
            ON CONFLICT (tenant_id, user_id, kind, idempotency_key) DO NOTHING RETURNING id',
            [$this->school->id(), $user, $kind, $key, json_encode($input, JSON_THROW_ON_ERROR)]);
        if ($created) RunTask::dispatch($this->school->id(), $user, $created->id)->afterCommit();
        return self::present(DB::selectOne('SELECT '.self::COLUMNS.' FROM school.job WHERE tenant_id = ? AND user_id = ? AND kind = ? AND idempotency_key = ?',
            [$this->school->id(), $user, $kind, $key]));
    }

    public static function present(object $job): array
    {
        return [...(array) $job, 'result' => $job->result === null ? null : json_decode($job->result, true, 512, JSON_THROW_ON_ERROR)];
    }

    /** A failed job, queued again; its steps resume without duplicating what was done. */
    public function retry(string $job): void
    {
        DB::update("UPDATE school.job SET status = 'queued' WHERE id = ? AND status = 'failed'", [$job]);
        RunTask::dispatch($this->school->id(), (int) auth()->id(), $job)->afterCommit();
    }
}
