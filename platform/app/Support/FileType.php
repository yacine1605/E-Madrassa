<?php

namespace App\Support;

use ZipArchive;

/**
 * A file's real type, read from its content, never from its name alone (FR-FIC-01 AC1): an
 * executable renamed `.pdf` is nothing the platform accepts. The name's extension must agree.
 */
final class FileType
{
    public const MEDIA = [
        'pdf' => 'application/pdf', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'csv' => 'text/csv',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];
    private const EXTENSIONS = ['pdf' => 'pdf', 'jpg' => 'jpeg', 'jpeg' => 'jpeg', 'png' => 'png', 'webp' => 'webp', 'docx' => 'docx', 'xlsx' => 'xlsx', 'csv' => 'csv'];

    /** The type code (`pdf`, `jpeg`, …), or null when the content is not one the platform accepts or the name says otherwise. */
    public static function detect(string $path, string $name): ?string
    {
        $type = self::sniff($path);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return $type !== null && (self::EXTENSIONS[$extension] ?? null) === $type ? $type : null;
    }

    /** A name safe to show and to send back: no path, no control characters, letters (Arabic too), digits and a few signs. */
    public static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $stem = pathinfo($name, PATHINFO_FILENAME);
        $stem = preg_replace('/[^\p{L}\p{N} ._-]+/u', '_', preg_replace('/[\p{C}]+/u', '', $stem));
        $stem = trim(preg_replace('/[_ ]{2,}/u', '_', $stem), ' ._-');
        $stem = mb_substr($stem === '' ? 'fichier' : $stem, 0, 150);
        return $extension === '' ? $stem : $stem.'.'.preg_replace('/[^a-z0-9]/', '', $extension);
    }

    private static function sniff(string $path): ?string
    {
        $head = (string) file_get_contents($path, false, null, 0, 16);
        if (str_starts_with($head, '%PDF-')) return 'pdf';
        if (str_starts_with($head, "\x89PNG\r\n\x1a\n")) return 'png';
        if (str_starts_with($head, "\xFF\xD8\xFF")) return 'jpeg';
        if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') return 'webp';
        if (str_starts_with($head, "PK\x03\x04")) {
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::RDONLY) !== true) return null;
            $type = $zip->locateName('[Content_Types].xml') === false ? null
                : ($zip->locateName('word/document.xml') !== false ? 'docx' : ($zip->locateName('xl/workbook.xml') !== false ? 'xlsx' : null));
            $zip->close();
            return $type;
        }
        // Text: UTF-8 (a BOM allowed), no NUL byte, in the first 64 KB.
        $sample = (string) file_get_contents($path, false, null, 0, 65536);
        $sample = str_starts_with($sample, "\xEF\xBB\xBF") ? substr($sample, 3) : $sample;
        if ($sample !== '' && !str_contains($sample, "\0") && mb_check_encoding(self::wholeCharacters($sample), 'UTF-8')) return 'csv';
        return null;
    }

    /** The sample without a multibyte character cut at its end. */
    private static function wholeCharacters(string $sample): string
    {
        for ($cut = 0; $cut < 4 && !mb_check_encoding(substr($sample, 0, strlen($sample) - $cut), 'UTF-8'); $cut++);
        return substr($sample, 0, strlen($sample) - $cut);
    }
}
