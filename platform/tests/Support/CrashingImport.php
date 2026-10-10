<?php

namespace Tests\Support;

use App\Support\Accounts;
use App\Tasks\Task;
use App\Tasks\TaskContext;
use RuntimeException;

/** An account import that crashes once, after its first batch is committed: the job must resume without duplicates. */
final class CrashingImport implements Task
{
    public static bool $crashed = false;

    public function __construct(private Accounts $accounts) {}

    public function run(TaskContext $task, array $input): array
    {
        $report = [];
        foreach (array_chunk($input['rows'], 100) as $number => $rows) {
            $report = Accounts::merge($report, $task->step(fn () => $this->accounts->import($rows, false, $number * 100 + 1)));
            if (!self::$crashed) {
                self::$crashed = true;
                throw new RuntimeException('worker lost');
            }
        }
        return $report;
    }
}
