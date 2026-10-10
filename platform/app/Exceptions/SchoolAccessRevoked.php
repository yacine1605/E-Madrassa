<?php

namespace App\Exceptions;

use RuntimeException;

/** A job's school was suspended, or its user lost access, before the job ran. */
final class SchoolAccessRevoked extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('school_access_revoked');
    }
}
