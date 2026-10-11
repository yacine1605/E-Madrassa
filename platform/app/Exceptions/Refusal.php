<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A refusal answered as `{message, code}`, rendered by the exception handler. Unlike
 * HttpResponseException, which the router turns into a response on the spot, it reaches
 * SchoolContext as an exception: the request's transaction is rolled back, whatever was
 * written before the refusal included.
 */
final class Refusal extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $status = 409, public readonly array $extra = [])
    {
        parent::__construct($message);
    }

    public static function permissionDenied(): self
    {
        return new self('permission_denied', 'Vous n’avez pas la permission nécessaire.', 403);
    }
}
