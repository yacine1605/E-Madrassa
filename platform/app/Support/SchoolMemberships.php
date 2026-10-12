<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** The same membership check for API sign-in and the school panel; never a client tenant context. */
final class SchoolMemberships
{
    public function forUser(int $user): Collection
    {
        $schools = collect(app(DatabaseContext::class)->reading(null, $user,
            fn () => DB::select('SELECT tenant_id, slug, name, default_locale, mode FROM school.my_schools()')));
        $pinned = config('platform.dedicated_school');
        return $pinned ? $schools->where('slug', $pinned)->values() : $schools;
    }
}
