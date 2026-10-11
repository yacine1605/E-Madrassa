<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Support\Tasks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** "Mes tâches" (FR-JOB-01): the signed-in user's own jobs in this school, as row-level security shows them. */
final class JobController
{
    public function index()
    {
        return ['data' => array_map(fn ($job) => Tasks::present($job),
            DB::select('SELECT '.Tasks::COLUMNS.' FROM school.job ORDER BY created_at DESC LIMIT 50'))];
    }

    public function show(string $job)
    {
        return ['data' => Tasks::present($this->find($job))];
    }

    /** A queued job stops at once; a running one at its next step. */
    public function cancel(string $job)
    {
        $current = $this->find($job);
        if ($current->status === 'queued') DB::update("UPDATE school.job SET status = 'cancelled' WHERE id = ?", [$job]);
        elseif ($current->status === 'running') DB::update('UPDATE school.job SET cancel_requested_at = now() WHERE id = ?', [$job]);
        else return Refusal::response('job_finished', 409, key: 'job_already_finished');
        return ['data' => Tasks::present($this->find($job))];
    }

    public function retry(string $job, Tasks $tasks)
    {
        if ($this->find($job)->status !== 'failed') return Refusal::response('not_failed', 409, key: 'job_not_failed');
        $tasks->retry($job);
        return response()->json(['data' => Tasks::present($this->find($job))], 202);
    }

    private function find(string $job): object
    {
        abort_unless(Str::isUuid($job), 404);
        return DB::selectOne('SELECT '.Tasks::COLUMNS.' FROM school.job WHERE id = ?', [$job]) ?? abort(404);
    }
}
