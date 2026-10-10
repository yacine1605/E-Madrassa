<?php

namespace App\Jobs;

use App\Exceptions\SchoolAccessRevoked;
use App\Support\DatabaseErrors;
use App\Tasks\Task;
use App\Tasks\TaskCancelled;
use App\Tasks\TaskContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one school job of the `school.job` table (FR-JOB-01): its kind's task, in its school.
 * A refusal of the database ends it as failed with the rule's message; a technical error is
 * tried again, then ends as failed with a plain message.
 */
final class RunTask implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public array $backoff = [10, 60];

    public function __construct(public string $tenantId, public int $userId, public string $jobId) {}

    public function handle(): void
    {
        $context = new TaskContext($this->tenantId, $this->userId, $this->jobId);
        try {
            $job = $context->begin();
        } catch (SchoolAccessRevoked) {
            return;
        }
        if (!$job) return;
        try {
            /** @var Task $task */
            $task = app(config('platform.tasks')[$job->kind]);
            $context->succeed($task->run($context, json_decode($job->input, true, 512, JSON_THROW_ON_ERROR)));
        } catch (TaskCancelled) {
            $context->cancelled();
        } catch (SchoolAccessRevoked) {
            $context->fail('school_access_revoked', 'Votre accès à cet établissement a été retiré : la tâche s’est arrêtée.');
        } catch (QueryException $e) {
            $refusal = DatabaseErrors::classify($e);
            $refusal ? $context->fail($refusal[1], $refusal[2]) : $this->transient($context, $e);
        } catch (Throwable $e) {
            $this->transient($context, $e);
        }
    }

    private function transient(TaskContext $context, Throwable $e): void
    {
        report($e);
        if ($this->attempts() < $this->tries) {
            $context->requeue('Erreur passagère : nouvel essai en cours.');
            $this->release($this->backoff[min($this->attempts(), count($this->backoff)) - 1] ?? 60);
            return;
        }
        $context->fail('technical_error', 'Erreur technique : la tâche a échoué. Relancez-la plus tard ; si cela se répète, prévenez le support.');
    }
}
