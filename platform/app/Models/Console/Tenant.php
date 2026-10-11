<?php

namespace App\Models\Console;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A school as the console reads it, on the `platform_admin` connection: read only. Every change
 * goes through the platform's functions (App\Support\Console), never through this model.
 */
final class Tenant extends Model
{
    protected $connection = 'console';
    protected $table = 'platform.tenant';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $guarded = ['*'];

    public function entitlement(): HasOne
    {
        return $this->hasOne(TenantEntitlement::class, 'tenant_id');
    }

    /** @return list<string> */
    public function modules(): array
    {
        return array_column($this->getConnection()->select('SELECT module_key FROM platform.tenant_module WHERE tenant_id = ? ORDER BY module_key', [$this->id]), 'module_key');
    }
}
