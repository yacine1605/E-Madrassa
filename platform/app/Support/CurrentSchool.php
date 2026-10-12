<?php

namespace App\Support;

use Illuminate\Support\Str;
use LogicException;

/**
 * The school a request or job works in. Set only by the SchoolContext middleware or the
 * WithinSchool job middleware, after the database confirmed the membership.
 */
final class CurrentSchool
{
    private ?string $id = null;
    private string $mode = 'active';
    private ?string $support = null;

    public function __construct(private DatabaseContext $context) {}

    /** @param string|null $support the consent a support session works under (FR-PLT-04) */
    public function enter(string $tenant, int $user, string $requestId, ?string $ip = null, ?string $support = null): void
    {
        // Signed, and local to the transaction: reset on commit or rollback.
        $this->context->enter($tenant, $user, $support, $requestId, $ip);
        $this->id = $tenant;
        $this->support = $support;
    }

    public function support(): ?string { return $this->support; }

    public function setMode(string $mode): void { $this->mode = $mode; }

    public function id(): string
    {
        return $this->id ?? throw new LogicException('No school context: route or job outside SchoolContext.');
    }

    public function mode(): string { return $this->mode; }

    /** Cache keys are separated per school (NFR-TEN-03). */
    public function cacheKey(string $key): string { return 'school:'.$this->id().':'.$key; }

    /** File paths are separated per school (ARC-07, NFR-TEN-03). */
    public function storagePath(string $path): string
    {
        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, '..')) throw new LogicException('Invalid school file path.');
        return 'schools/'.$this->id().'/'.$path;
    }

    public static function requestId(?string $header): string
    {
        return is_string($header) && preg_match('/^[A-Za-z0-9-]{8,64}$/', $header) ? $header : (string) Str::uuid();
    }
}
