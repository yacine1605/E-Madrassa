<?php

namespace App\Providers;

use App\Support\Credentials;
use App\Support\CredentialsUserProvider;
use App\Support\CurrentSchool;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class PlatformServiceProvider extends ServiceProvider
{
    /** One connection per database role (deploy/roles.sql), each with its own credentials. */
    private const CONNECTIONS = [
        'pgsql' => 'DB_',            // school_runtime: schools' requests and jobs, under RLS
        'console' => 'DB_CONSOLE_',  // platform_admin: publisher console, no school data
        'auth' => 'DB_AUTH_',        // platform_auth: passwords and MFA secrets only (App\Support\Credentials)
        'owner' => 'DB_OWNER_',      // migration owner: migrations and provisioning commands only
    ];

    public function register(): void
    {
        $base = config('database.connections.pgsql');
        foreach (self::CONNECTIONS as $name => $prefix) {
            // Credentials are read from this process's environment on every boot, never
            // from a config cache built in another shell: a web request cannot end up with
            // the owner's credentials after `artisan config:cache` (lesson of T22).
            $connection = $base;
            $connection['username'] = env($prefix.'USERNAME');
            $connection['password'] = (string) env($prefix.'PASSWORD');
            config(["database.connections.$name" => $connection]);
        }
        $this->app->scoped(CurrentSchool::class, fn () => new CurrentSchool());
    }

    public function boot(): void
    {
        Auth::provider('credentials', fn ($app, array $config) => new CredentialsUserProvider($app['hash'], $config['model'], $app->make(Credentials::class)));
        // Flood limits before sign-in (NFR-SEC-05); the lockout of FR-IAM-02 is LoginThrottle's.
        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('recovery', fn (Request $request) => [
            Limit::perHour(10)->by($request->ip()),
            Limit::perHour(3)->by(hash('sha256', mb_strtolower(trim((string) $request->input('email'))))),
        ]);
    }
}
