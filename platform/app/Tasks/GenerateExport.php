<?php

namespace App\Tasks;

use App\Support\Exporter;

/** `exports.generate` (FR-IMP-02): a large export, made in the background; its author is notified when it is ready. */
final class GenerateExport implements Task
{
    public function __construct(private Exporter $exporter) {}

    public function run(TaskContext $task, array $input): array
    {
        $export = $task->step(fn () => $this->exporter->run($input['kind'], $input['format'], $input['filters'], $input['locale']));
        return ['export_id' => $export['id'], 'file_id' => $export['file']->id, 'rows' => $export['rows']];
    }
}
