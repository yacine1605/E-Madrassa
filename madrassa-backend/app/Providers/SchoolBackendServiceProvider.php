<?php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final class SchoolBackendServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(\App\School\Modules\ModuleRegistry::class, fn () => new \App\School\Modules\ModuleRegistry(
            config('school_modules.definitions', []), (string) config('school_modules.enabled', '*')
        ));
        $connection = config('school_backend.connection');
        // Credentials come from this process's environment on every boot, never from a
        // config cache built in another shell: requests and jobs keep the restricted role
        // even after `artisan config:cache` was run with the owner's credentials.
        if (($username = getenv('DB_USERNAME')) !== false) {
            $connection['username'] = $username;
            $connection['password'] = (string) getenv('DB_PASSWORD');
        }
        config(['database.connections.pgsql'=>$connection]);
        if (!config('database.redis')) config(['database.redis'=>config('school_backend.redis')]);
    }
    public function boot(): void
    {
        // Invalid configuration fails at startup, before serving requests.
        $this->app->make(\App\School\Modules\ModuleRegistry::class);
        if ($this->app->runningInConsole()) $this->commands([\App\School\Console\ModulesCommand::class]);
        $this->loadRoutesFrom(base_path('routes/school.php'));
        RateLimiter::for('school-login', fn (Request $r) => [Limit::perMinute(5)->by($r->ip()),
            Limit::perMinute(5)->by(hash('sha256', strtolower((string) $r->input('email'))))]);
        RateLimiter::for('school-api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?? $r->ip()));
    }
}
