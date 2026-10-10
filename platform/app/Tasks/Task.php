<?php

namespace App\Tasks;

/**
 * A kind of background job (FR-JOB-01). Its work goes through $task->step(), and every step
 * can be run again without duplicating its effects (AC2): a job resumed after a crash runs
 * from the start.
 */
interface Task
{
    /** @return array the result shown to the user */
    public function run(TaskContext $task, array $input): array;
}
