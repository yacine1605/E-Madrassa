<?php

namespace App\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use WeakMap;

/**
 * Who a transaction works for, as the database believes it (SECURITY-REVIEW.md, F7): the school,
 * the person, the support consent and the request, signed with a key the application's database
 * roles cannot read. school.context() accepts the signature only on the connection it was made
 * for, in a transaction that began before it: an SQL injection can neither name another school
 * or person nor replay another request's context. Local to the transaction, like SET LOCAL.
 */
final class DatabaseContext
{
    /** @var WeakMap<PDO, int> the server process behind each connection, asked once */
    private WeakMap $processes;

    public function __construct()
    {
        $this->processes = new WeakMap();
    }

    public function enter(?string $tenant, ?int $user, ?string $support = null, ?string $requestId = null, ?string $ip = null,
        ?Connection $db = null): void
    {
        $db ??= DB::connection();
        $pdo = $db->getPdo();
        $this->processes[$pdo] ??= (int) $db->selectOne('SELECT pg_backend_pid() AS pid')->pid;
        $payload = json_encode(['tenant' => $tenant, 'user' => $user, 'support' => $support, 'request' => $requestId, 'ip' => $ip,
            'pid' => $this->processes[$pdo], 'at' => (int) (microtime(true) * 1_000_000)], JSON_THROW_ON_ERROR);
        $db->select("SELECT set_config('app.context', ?, true)", [$payload.'.'.hash_hmac('sha256', $payload, self::key())]);
    }

    /**
     * Reads in this context alone, in a transaction, or a savepoint of the current one, rolled
     * back afterwards: the context around it comes back unchanged. For what precedes a school's
     * request: the person signed in, their schools and memberships, a support consent.
     *
     * @template T
     * @param callable(): T $read
     * @return T
     */
    public function reading(?string $tenant, int $user, callable $read, ?string $support = null): mixed
    {
        DB::beginTransaction();
        try {
            $this->enter($tenant, $user, $support);
            return $read();
        } finally {
            DB::rollBack();
        }
    }

    /**
     * Works as the person alone, in a transaction, or a savepoint of the current one, that is
     * kept: for what a person changes of their own before any school, their language. The
     * context around it, if any, is set again afterwards; a failure rolls it back by itself.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function acting(int $user, callable $work): mixed
    {
        $around = DB::transactionLevel() > 0 ? DB::selectOne("SELECT current_setting('app.context', true) AS c")->c : null;
        $result = DB::transaction(function () use ($user, $work) {
            $this->enter(null, $user);
            return $work();
        });
        if ($around !== null) DB::select("SELECT set_config('app.context', ?, true)", [$around]);
        return $result;
    }

    /**
     * Derived from the application key, which never reaches the database; `platform:roles` gives
     * the database this one. A new application key gives a new one at the next deployment.
     */
    public static function key(): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) $key = base64_decode(substr($key, 7));
        return hash_hmac('sha256', 'madrassa:database-context', $key, true);
    }
}
