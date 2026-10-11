<?php

namespace App\Tasks;

use App\Support\Imports;

/** `imports.run` (FR-IMP-01): the confirmed import, by batches committed one by one. */
final class RunImport implements Task
{
    public function __construct(private Imports $imports) {}

    public function run(TaskContext $task, array $input): array
    {
        return ['counts' => $this->imports->run($task, $input['batch'])['counts']];
    }
}
