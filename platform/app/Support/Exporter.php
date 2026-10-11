<?php

namespace App\Support;

use App\Exports\Dataset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Exports (FR-IMP-02) in CSV, Excel and PDF: the columns the user may see, cells that start a
 * formula made inert (AC2), the file kept seven days among the user's exports, every export
 * logged with its filters and its number of rows.
 */
final class Exporter
{
    public const KEPT_DAYS = 7;

    public function __construct(private Files $files, private Permissions $permissions, private Pdf $pdf, private CurrentSchool $school) {}

    public function dataset(string $kind): Dataset
    {
        return app(config('platform.exports')[$kind]);
    }

    /** Beyond this number of rows, the export runs in the background (NFR-PERF-04). */
    public static function backgroundRows(): int
    {
        return (int) config('platform.export_background_rows', 2000);
    }

    /** AC1: a column that requires a permission is left out for whoever lacks it. */
    public function columns(Dataset $dataset, string $locale): array
    {
        return array_values(array_filter($dataset->columns($locale), fn ($column) => !isset($column['permission']) || $this->permissions->can($column['permission'])));
    }

    /** @return array{id: string, file: object, rows: int, columns: list<string>} */
    public function run(string $kind, string $format, array $filters, string $locale): array
    {
        $dataset = $this->dataset($kind);
        $columns = $this->columns($dataset, $locale);
        $rows = [];
        foreach ($dataset->rows($filters, $locale) as $row) {
            $rows[] = array_map(fn ($column) => $row[$column['key']] ?? null, $columns);
        }
        $labels = array_column($columns, 'label');
        $title = $dataset->title($locale);
        $reference = 'EXP-'.now()->timezone('Africa/Algiers')->format('Ymd').'-'.Str::upper(Str::random(6));
        $contents = match ($format) {
            'csv' => $this->csv($labels, $rows),
            'xlsx' => $this->xlsx($labels, $rows, $title, $locale === 'ar'),
            'pdf' => $this->pdf->render('list', ['columns' => $labels, 'rows' => $rows, 'filters' => $this->describe($filters)], $locale, $reference, $title, 'A4-L'),
        };
        $name = Str::slug($kind).'-'.now()->timezone('Africa/Algiers')->format('Ymd-His').'.'.$format;
        $file = $this->files->store($contents, 'export', $name, $format, now()->addDays(self::KEPT_DAYS));
        $id = DB::selectOne('INSERT INTO school.export_log (tenant_id, user_id, kind, format, filters, columns, row_count, file_id)
            VALUES (?, ?, ?, ?, ?::jsonb, (SELECT coalesce(array_agg(value), \'{}\') FROM json_array_elements_text(?::json)), ?, ?) RETURNING id',
            [$this->school->id(), auth()->id() ?? DB::selectOne('SELECT school.actor() AS a')->a, $kind, $format, json_encode((object) $filters, JSON_THROW_ON_ERROR),
             json_encode(array_column($columns, 'key'), JSON_THROW_ON_ERROR), count($rows), $file->id])->id;
        return ['id' => $id, 'file' => $file, 'rows' => count($rows), 'columns' => array_column($columns, 'key')];
    }

    /** AC2, NFR-SEC-03: a cell that a spreadsheet would read as a formula becomes text. */
    public static function inert(mixed $value): mixed
    {
        return is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    /** UTF-8 with its mark, so that Excel reads Arabic and accents; semicolons, as French-language Excel expects. */
    private function csv(array $labels, array $rows): string
    {
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        foreach ([$labels, ...$rows] as $row) fputcsv($out, array_map(self::inert(...), $row), ';', '"', '', "\r\n");
        rewind($out);
        $contents = stream_get_contents($out);
        fclose($out);
        return $contents;
    }

    private function xlsx(array $labels, array $rows, string $title, bool $rtl): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $writer = new Writer();
        $writer->openToFile($path);
        $sheet = $writer->getCurrentSheet();
        $sheet->setName(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/u', ' ', $title), 0, 31));
        if ($rtl) $sheet->setSheetView((new SheetView())->setRightToLeft(true));
        $writer->addRow(Row::fromValues($labels));
        foreach ($rows as $row) $writer->addRow(Row::fromValues(array_map(self::inert(...), $row)));
        $writer->close();
        $contents = file_get_contents($path);
        unlink($path);
        return $contents;
    }

    private function describe(array $filters): string
    {
        return implode(' · ', array_map(fn ($key, $value) => $key.' = '.(is_bool($value) ? __($value ? 'exports.yes' : 'exports.no') : $value),
            array_keys($filters), $filters));
    }
}
