<?php

namespace App\Imports;

use RuntimeException;

/** A row the import refuses, with its cause in words (FR-IMP-01 AC2: line and cause). */
final class RowRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
