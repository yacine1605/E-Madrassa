<?php

namespace App\Imports;

/**
 * A kind of import (FR-IMP-01): the columns of its template, the rules of a row, how a row is
 * applied (found by its stable reference: updated or left as it is, never duplicated, BR-05)
 * and how it is undone.
 */
interface Importer
{
    /** The permission that creates these records on screen. */
    public function permission(): string;

    /** @return array<string, mixed> the template's columns, in order, with the rules of each */
    public function rules(): array;

    /** The row's stable reference. */
    public function key(array $row): string;

    /**
     * Applies a valid row in the current transaction; throws RowRefused (unknown reference…) or
     * lets the database refuse it. The options are the batch's; a preview passes none.
     *
     * @return array{outcome: string, target_type: string, target_id: string, previous: ?array}
     */
    public function apply(array $row, array $options = []): array;

    /** Undoes an applied row; throws DependentOperation when something done since depends on it. */
    public function undo(string $outcome, string $targetId, ?array $previous, array $row): void;
}
