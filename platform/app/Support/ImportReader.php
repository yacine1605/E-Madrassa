<?php

namespace App\Support;

use DateTimeInterface;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;

/**
 * Reads an import file, CSV or Excel: the header names the columns (the template's codes), each
 * following row becomes values keyed by column, numbered by its line in the file. Empty cells
 * are null; a value our exports made inert (`'=…`) comes back as it was.
 */
final class ImportReader
{
    /** @return array{header: list<string>, rows: list<array{line: int, values: array<string, ?string>}>} */
    public static function read(string $path, string $type, int $maxRows): array
    {
        $lines = $type === 'xlsx' ? self::xlsx($path) : self::csv($path);
        $header = null;
        $rows = [];
        foreach ($lines as $number => $cells) {
            if ($header === null) {
                $header = array_map(fn ($cell) => mb_strtolower(trim((string) $cell)), $cells);
                continue;
            }
            if (count(array_filter($cells, fn ($cell) => $cell !== null && trim((string) $cell) !== '')) === 0) continue;
            if (count($rows) >= $maxRows) throw new RuntimeException("Le fichier dépasse $maxRows lignes.");
            $values = [];
            foreach ($header as $index => $column) {
                if ($column !== '') $values[$column] = self::value($cells[$index] ?? null);
            }
            $rows[] = ['line' => $number + 1, 'values' => $values];
        }
        return ['header' => $header ?? [], 'rows' => $rows];
    }

    private static function value(mixed $cell): ?string
    {
        if ($cell instanceof DateTimeInterface) return $cell->format('Y-m-d');
        if ($cell === null || is_string($cell) && trim($cell) === '') return null;
        $value = trim((string) $cell);
        return preg_match("/^'[=+\\-@]/", $value) ? substr($value, 1) : $value;
    }

    /** @return iterable<int, list<mixed>> lines numbered from 0 */
    private static function csv(string $path): iterable
    {
        $handle = fopen($path, 'rb');
        $first = (string) fgets($handle);
        rewind($handle);
        if (str_starts_with($first, "\xEF\xBB\xBF")) fread($handle, 3);
        $delimiter = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
        $number = 0;
        while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            yield $number++ => $cells;
        }
        fclose($handle);
    }

    private static function xlsx(string $path): iterable
    {
        // Empty rows kept, so that a line number is the one the spreadsheet shows.
        $options = new Options();
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $reader = new Reader($options);
        $reader->open($path);
        foreach ($reader->getSheetIterator() as $sheet) {
            $number = 0;
            foreach ($sheet->getRowIterator() as $row) yield $number++ => $row->toArray();
            break;
        }
        $reader->close();
    }
}
