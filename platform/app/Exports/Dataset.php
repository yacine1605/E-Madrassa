<?php

namespace App\Exports;

/**
 * What an export can contain (FR-IMP-02): rows read under the same permission and scopes as
 * the screen that shows them, columns that may each require a permission of their own (a
 * salary column is never exported to whoever cannot see salaries, AC1).
 */
interface Dataset
{
    /** The permission that reads these rows on screen. */
    public function permission(): string;

    public function title(string $locale): string;

    /** @return list<array{key: string, label: string, permission?: string}> */
    public function columns(string $locale): array;

    /** Validation rules of the filters. */
    public function filters(): array;

    public function count(array $filters): int;

    /** @return iterable<array<string, string|int|float|bool|null>> each row keyed by column */
    public function rows(array $filters, string $locale): iterable;
}
