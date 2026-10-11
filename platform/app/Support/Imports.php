<?php

namespace App\Support;

use App\Exceptions\Refusal;
use App\Imports\DependentOperation;
use App\Imports\Importer;
use App\Imports\RowRefused;
use App\Tasks\TaskContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * The import engine (FR-IMP-01). A file is kept, then previewed in the background: every row
 * is checked and applied for real inside a transaction that is rolled back, so the preview
 * finds every refusal and leaves no business data (AC3). Confirmed, the valid rows are applied
 * by batches, each committed on its own, found by their reference (AC1); a complete import is
 * undone while nothing depends on it. Refused rows are reported with their line and cause (AC2).
 */
final class Imports
{
    public const MAX_ROWS = 10000;
    public const BATCH_COLUMNS = 'id, user_id, kind, file_id, options, status, error_message, created_at, previewed_at, confirmed_at, imported_at, rolled_back_at';
    private const BATCH = 100;

    public function __construct(private CurrentSchool $school, private Files $files, private Tasks $tasks) {}

    public function importer(string $kind): Importer
    {
        return app(config('platform.imports')[$kind]);
    }

    /** @return array{batch: array, job: array} */
    public function start(UploadedFile $upload, string $kind, array $options): array
    {
        $file = $this->files->upload($upload, 'import');
        $id = DB::selectOne('INSERT INTO school.import_batch (tenant_id, user_id, kind, file_id, options) VALUES (?, ?, ?, ?, ?::jsonb) RETURNING id',
            [$this->school->id(), auth()->id(), $kind, $file->id, json_encode((object) $options, JSON_THROW_ON_ERROR)])->id;
        return ['batch' => $this->summary($id), 'job' => $this->tasks->start('imports.preview', 'preview-'.$id, ['batch' => $id])];
    }

    public function preview(TaskContext $task, string $batchId): array
    {
        return $task->step(function () use ($batchId) {
            $batch = DB::selectOne('SELECT kind, file_id, status FROM school.import_batch WHERE id = ? FOR UPDATE', [$batchId]);
            if ($batch->status !== 'previewing') return $this->summary($batchId);
            $importer = $this->importer($batch->kind);
            try {
                $file = $this->read($batch->file_id);
                $missing = array_diff($this->requiredColumns($importer), $file['header']);
                if ($missing) throw new RuntimeException(__('errors.columns_missing', ['columns' => implode(', ', $missing)]));
            } catch (RuntimeException $e) {
                DB::update("UPDATE school.import_batch SET status = 'failed', error_message = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 1000), $batchId]);
                return $this->summary($batchId);
            }
            foreach ($this->dryRun($importer, $file['rows']) as $result) {
                DB::insert('INSERT INTO school.import_row (tenant_id, batch_id, line, external_ref, data, outcome, action, error_code, error_message)
                    VALUES (?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?)', [$this->school->id(), $batchId, $result['line'], $result['key'],
                    json_encode((object) $result['data'], JSON_THROW_ON_ERROR), $result['error'] ? 'rejected' : 'valid',
                    $result['error'] ? null : ['created' => 'create', 'updated' => 'update', 'unchanged' => 'unchanged'][$result['applied']['outcome']],
                    $result['error'][0] ?? null, $result['error'][1] ?? null]);
            }
            DB::update("UPDATE school.import_batch SET status = 'previewed' WHERE id = ?", [$batchId]);
            return $this->summary($batchId);
        });
    }

    public function confirm(string $batchId): array
    {
        if (!DB::update("UPDATE school.import_batch SET status = 'importing' WHERE id = ? AND status = 'previewed'", [$batchId])) {
            throw Refusal::of('not_previewed');
        }
        return ['batch' => $this->summary($batchId), 'job' => $this->tasks->start('imports.run', 'run-'.$batchId, ['batch' => $batchId])];
    }

    /** The valid rows, by batches committed one by one; run again, it goes on where it stopped (FR-JOB-01 AC2). */
    public function run(TaskContext $task, string $batchId): array
    {
        $batch = $task->step(fn () => DB::selectOne('SELECT kind, status, options FROM school.import_batch WHERE id = ?', [$batchId]));
        if ($batch->status !== 'importing') return $task->step(fn () => $this->summary($batchId));
        $importer = $this->importer($batch->kind);
        $options = json_decode($batch->options, true);
        $total = max(1, (int) $task->step(fn () => DB::selectOne("SELECT count(*) AS n FROM school.import_row WHERE batch_id = ? AND outcome = 'valid'", [$batchId])->n));
        $done = 0;
        do {
            $count = $task->step(function () use ($importer, $batchId, $options) {
                $rows = DB::select("SELECT id, line, data FROM school.import_row WHERE batch_id = ? AND outcome = 'valid' ORDER BY line LIMIT ".self::BATCH, [$batchId]);
                foreach ($rows as $row) {
                    $result = $this->attempt($importer, ['line' => $row->line, 'values' => json_decode($row->data, true)], $options);
                    DB::update('UPDATE school.import_row SET outcome = ?, error_code = ?, error_message = ?, target_type = ?, target_id = ?, previous = ?::jsonb WHERE id = ?', [
                        $result['error'] ? 'rejected' : $result['applied']['outcome'], $result['error'][0] ?? null, $result['error'][1] ?? null,
                        $result['applied']['target_type'] ?? null, $result['applied']['target_id'] ?? null,
                        isset($result['applied']['previous']) ? json_encode($result['applied']['previous'], JSON_THROW_ON_ERROR) : null, $row->id]);
                }
                return count($rows);
            });
            $done += $count;
            $task->progress((int) floor(100 * min($done, $total) / $total));
        } while ($count > 0);
        return $task->step(function () use ($batchId) {
            DB::update("UPDATE school.import_batch SET status = 'imported' WHERE id = ? AND status = 'importing'", [$batchId]);
            return $this->summary($batchId);
        });
    }

    /** The whole import undone, last row first, in the request's transaction: all or nothing. */
    public function undo(string $batchId): array
    {
        $batch = DB::selectOne('SELECT kind, status FROM school.import_batch WHERE id = ? FOR UPDATE', [$batchId]) ?? abort(404);
        if ($batch->status !== 'imported') throw Refusal::of('not_imported');
        $importer = $this->importer($batch->kind);
        $rows = DB::select("SELECT id, line, outcome, target_id, previous, data FROM school.import_row
            WHERE batch_id = ? AND outcome IN ('created', 'updated') ORDER BY line DESC", [$batchId]);
        foreach ($rows as $row) {
            try {
                DB::transaction(fn () => $importer->undo($row->outcome, $row->target_id, $row->previous ? json_decode($row->previous, true) : null, json_decode($row->data, true)));
            } catch (DependentOperation $e) {
                throw new DependentOperation($row->line, $e->getMessage());
            } catch (QueryException $e) {
                $refusal = DatabaseErrors::classify($e) ?? throw $e;
                throw new DependentOperation($row->line, $refusal[2]);
            }
            DB::update("UPDATE school.import_row SET outcome = 'undone' WHERE id = ?", [$row->id]);
        }
        DB::update("UPDATE school.import_batch SET status = 'rolled_back' WHERE id = ?", [$batchId]);
        return $this->summary($batchId);
    }

    public function summary(string $batchId): array
    {
        $batch = DB::selectOne('SELECT '.self::BATCH_COLUMNS.' FROM school.import_batch WHERE id = ?', [$batchId]) ?? abort(404);
        $counts = ['total' => 0, 'valid' => 0, 'rejected' => 0, 'create' => 0, 'update' => 0, 'unchanged' => 0, 'created' => 0, 'updated' => 0, 'undone' => 0];
        foreach (DB::select('SELECT outcome, action, count(*) AS n FROM school.import_row WHERE batch_id = ? GROUP BY 1, 2', [$batchId]) as $group) {
            $counts['total'] += $group->n;
            $counts[$group->outcome] = ($counts[$group->outcome] ?? 0) + $group->n;
            if ($group->outcome === 'valid' && $group->action) $counts[$group->action] += $group->n;
        }
        return [...(array) $batch, 'options' => json_decode($batch->options, true), 'counts' => $counts];
    }

    /** AC2: the rejection report, a line per refused row with its cause. */
    public function report(string $batchId): string
    {
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['ligne', 'reference', 'code', 'cause'], ';', '"', '', "\r\n");
        foreach (DB::select("SELECT line, external_ref, error_code, error_message FROM school.import_row WHERE batch_id = ? AND outcome = 'rejected' ORDER BY line", [$batchId]) as $row) {
            fputcsv($out, array_map(Exporter::inert(...), [$row->line, $row->external_ref, $row->error_code, $row->error_message]), ';', '"', '', "\r\n");
        }
        rewind($out);
        return stream_get_contents($out);
    }

    /** The template: the columns' codes on the first line. */
    public function template(string $kind, string $format): string
    {
        $columns = array_keys($this->importer($kind)->rules());
        if ($format === 'csv') return "\xEF\xBB\xBF".implode(';', $columns)."\r\n";
        $path = tempnam(sys_get_temp_dir(), 'tpl');
        $writer = new Writer();
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($columns));
        $writer->close();
        $contents = file_get_contents($path);
        unlink($path);
        return $contents;
    }

    private function dryRun(Importer $importer, array $rows): array
    {
        $results = [];
        try {
            DB::transaction(function () use ($importer, $rows, &$results) {
                $seen = [];
                foreach ($rows as $row) $results[] = $this->attempt($importer, $row, [], $seen);
                throw new DryRunOver();
            });
        } catch (DryRunOver) {
        }
        return $results;
    }

    /** A row: validated, then applied in its own savepoint. */
    private function attempt(Importer $importer, array $row, array $options, ?array &$seen = null): array
    {
        $result = ['line' => $row['line'], 'key' => null, 'data' => $row['values'], 'error' => null, 'applied' => null];
        $validator = Validator::make($row['values'], $importer->rules());
        if ($validator->fails()) return [...$result, 'error' => ['invalid', mb_substr(implode(' ', $validator->errors()->all()), 0, 1000)]];
        $data = $validator->validated();
        $result['key'] = $importer->key($data);
        if ($seen !== null) {
            if (isset($seen[$result['key']])) return [...$result, 'error' => ['duplicate_in_file', __('errors.duplicate_in_file', ['line' => $seen[$result['key']]])]];
            $seen[$result['key']] = $row['line'];
        }
        try {
            $result['applied'] = DB::transaction(fn () => $importer->apply($data, $options));
        } catch (RowRefused $e) {
            $result['error'] = [$e->reason, $e->getMessage()];
        } catch (QueryException $e) {
            $refusal = DatabaseErrors::classify($e) ?? throw $e;
            $result['error'] = [$refusal[1], $refusal[2]];
        }
        return $result;
    }

    private function requiredColumns(Importer $importer): array
    {
        return array_keys(array_filter($importer->rules(), fn ($rule) => in_array('required', is_array($rule) ? $rule : explode('|', $rule), true)));
    }

    /** @return array{header: list<string>, rows: list<array>} */
    private function read(string $fileId): array
    {
        $file = DB::selectOne('SELECT storage_key, file_type FROM school.file_object WHERE id = ?', [$fileId]);
        $path = tempnam(sys_get_temp_dir(), 'import');
        file_put_contents($path, Storage::disk('files')->get($file->storage_key));
        try {
            return ImportReader::read($path, $file->file_type, self::MAX_ROWS);
        } finally {
            unlink($path);
        }
    }
}
