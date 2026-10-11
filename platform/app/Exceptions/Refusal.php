<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A refusal answered as `{message, code}`, rendered by the exception handler. Unlike
 * HttpResponseException, which the router turns into a response on the spot, it reaches
 * SchoolContext as an exception: the request's transaction is rolled back, whatever was
 * written before the refusal included. Its message is the code's, in the reader's language.
 */
final class Refusal extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $status = 409, public readonly array $extra = [])
    {
        parent::__construct($message);
    }

    /** The refusal of this code, with the message of the catalog `errors` (or of `$key`). */
    public static function of(string $code, int $status = 409, array $replace = [], array $extra = [], ?string $key = null): self
    {
        return new self($code, __('errors.'.($key ?? $code), $replace), $status, $extra);
    }

    public static function permissionDenied(): self
    {
        return self::of('permission_denied', 403);
    }

    /** The same refusal as a response, where nothing written needs undoing. */
    public static function response(string $code, int $status, array $replace = [], ?string $key = null): JsonResponse
    {
        return response()->json(['message' => __('errors.'.($key ?? $code), $replace), 'code' => $code], $status);
    }
}
