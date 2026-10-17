<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * FR-TDB-03: the dashboards' figures kept for their reader, computed again as soon as anything
 * changes in the school (its stamp, SQL 076), on another day, after five minutes at most, or when
 * the reader asks for them afresh. Kept for one reader only: their rights choose what the figures
 * count (SQL 070), and the same rights read them again. Each result keeps the time it was computed,
 * which the screens show.
 */
final class DashboardCache
{
    private const SECONDS = 300;

    /** The figures of this dashboard and these parameters; `$fresh` computes them again. */
    public static function remember(string $dashboard, array $parameters, Closure $compute, bool $fresh = false): array
    {
        if ($fresh) self::refresh();
        $key = 'dashboard:'.hash('sha256', json_encode([$dashboard, $parameters, app(CurrentSchool::class)->id(), Auth::id(), app()->getLocale(),
            now('Africa/Algiers')->toDateString(), DB::selectOne('SELECT school.dashboard_stamp() AS stamp')->stamp, Cache::get(self::epoch(), 0)]));
        return Cache::remember($key, self::SECONDS, $compute);
    }

    /** The reader's figures computed afresh at their next reading. */
    public static function refresh(): void
    {
        Cache::forever(self::epoch(), Cache::get(self::epoch(), 0) + 1);
    }

    private static function epoch(): string
    {
        return 'dashboard:epoch:'.(Auth::id() ?? 0);
    }
}
