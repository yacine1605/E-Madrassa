<?php
namespace App\Support\Concerns;

use App\School\Modules\ModuleRegistry;
use App\School\Services\DeploymentSchool;
use Illuminate\Support\Facades\DB;

trait HasModuleAccess
{
    public static function canAccess(): bool
    {
        if (!config('school.backend')) return config('school.demo') && app()->environment('local');
        $user = auth()->user();
        if (!$user) return false;
        // Request-local only: permission revocation is visible on the next request,
        // including Livewire hydration. No shared singleton caches user grants.
        if (!request()->attributes->has('school.module.grants')) {
            $member = DB::transaction(fn () => DeploymentSchool::membership($user->id));
            request()->attributes->set('school.module.grants', $member ? json_decode($member->permissions, true) : null);
        }
        $grants = request()->attributes->get('school.module.grants');
        return is_array($grants) && app(ModuleRegistry::class)->canAccessPage(static::$module, $grants);
    }
}
