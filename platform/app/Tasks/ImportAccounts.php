<?php

namespace App\Tasks;

use App\Support\Accounts;

/**
 * `accounts.import` (FR-IMP-01, FR-IAM-01 AC3): by batches of 100 rows, each committed on its
 * own. Run again after a crash, rows already imported are found by their reference and left
 * as they are: nothing is duplicated (FR-JOB-01 AC2).
 */
final class ImportAccounts implements Task
{
    private const BATCH = 100;

    public function __construct(private Accounts $accounts) {}

    public function run(TaskContext $task, array $input): array
    {
        $batches = array_chunk($input['rows'], self::BATCH);
        $report = [];
        foreach ($batches as $number => $rows) {
            $batch = $task->step(fn () => $this->accounts->import($rows, (bool) ($input['invite'] ?? false), $number * self::BATCH + 1));
            $report = Accounts::merge($report, $batch);
            $task->progress((int) floor(100 * ($number + 1) / count($batches)));
        }
        return $report;
    }
}
