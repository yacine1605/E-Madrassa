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
        config(['database.connections.pgsql'=>config('school_backend.connection')]);
        if (!config('database.redis')) config(['database.redis'=>config('school_backend.redis')]);
    }
    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/school.php'));
        RateLimiter::for('school-login', fn (Request $r) => [Limit::perMinute(5)->by($r->ip()),
            Limit::perMinute(5)->by(hash('sha256', strtolower((string) $r->input('email'))))]);
        RateLimiter::for('school-api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?? $r->ip()));
    }
}
