<?php

namespace App\Imports;

use RuntimeException;

/** An import cannot be undone: something done since depends on one of its rows. */
final class DependentOperation extends RuntimeException
{
    public function __construct(public readonly int $importLine, string $message)
    {
        parent::__construct($message);
    }
}
