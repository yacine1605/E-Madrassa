<?php

namespace App\Tasks;

use App\Support\Imports;

/** `imports.preview` (FR-IMP-01): every row checked and tried, nothing kept but the verdicts. */
final class PreviewImport implements Task
{
    public function __construct(private Imports $imports) {}

    public function run(TaskContext $task, array $input): array
    {
        return ['counts' => $this->imports->preview($task, $input['batch'])['counts']];
    }
}
