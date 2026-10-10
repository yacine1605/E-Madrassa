<?php

namespace App\Support;

use Illuminate\Contracts\Cache\Repository;

/**
 * FR-IAM-02, NFR-SEC-05: progressive lockout, per account and per address. After the
 * threshold of consecutive failures, each new failure doubles the wait. The account key is
 * the login as typed, whether or not it exists, so the answer reveals nothing.
 */
final class LoginThrottle
{
    public function __construct(private Repository $cache) {}

    /** Seconds before another attempt is allowed; 0 when one is. */
    public function wait(string $login, string $ip): int
    {
        $until = max($this->state($this->account($login))['until'], $this->state($this->address($ip))['until']);
        return max(0, $until - now()->getTimestamp());
    }

    public function failed(string $login, string $ip): void
    {
        $thresholds = [$this->account($login) => config('platform.lockout_threshold'), $this->address($ip) => config('platform.lockout_ip_threshold')];
        foreach ($thresholds as $key => $threshold) {
            $state = $this->state($key);
            $state['failures']++;
            $over = $state['failures'] - (int) $threshold;
            if ($over >= 0) {
                $minutes = min((int) config('platform.lockout_max_minutes'), (int) config('platform.lockout_first_minutes') * 2 ** $over);
                $state['until'] = now()->getTimestamp() + $minutes * 60;
            }
            $this->cache->put($key, $state, now()->addDay());
        }
    }

    /** A success clears the account's count; the address keeps its own, against password spraying. */
    public function succeeded(string $login): void
    {
        $this->cache->forget($this->account($login));
    }

    private function state(string $key): array
    {
        return $this->cache->get($key) ?? ['failures' => 0, 'until' => 0];
    }

    private function account(string $login): string
    {
        return 'lockout:account:'.hash('sha256', mb_strtolower(trim($login)));
    }

    private function address(string $ip): string
    {
        return 'lockout:ip:'.hash('sha256', $ip);
    }
}
