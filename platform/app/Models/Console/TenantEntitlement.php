<?php

namespace App\Models\Console;

use Illuminate\Database\Eloquent\Model;

/** A school's plan, validity and quotas (FR-PLT-02), read by the console; changed by platform.set_entitlement(). */
final class TenantEntitlement extends Model
{
    protected $connection = 'console';
    protected $table = 'platform.tenant_entitlement';
    protected $primaryKey = 'tenant_id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
    protected $guarded = ['*'];
    protected $casts = ['valid_from' => 'date', 'valid_until' => 'date'];
}
