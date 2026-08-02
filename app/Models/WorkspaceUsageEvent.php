<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkspaceUsageEvent extends Model
{
    protected $table = 'workspace_usage_events';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'workspace_id',
        'billing_account_id',
        'type',
        'credit_bucket',
        'units',
        'credit_cost',
        'provider',
        'provider_cost_usd',
        'provider_cost_reserved_usd',
        'provider_cost_actual_usd',
        'source',
        'status',
        'reference_id',
        'metadata',
        'error_message',
        'consumed_at',
        'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'provider_cost_usd' => 'decimal:4',
            'provider_cost_reserved_usd' => 'decimal:4',
            'provider_cost_actual_usd' => 'decimal:4',
            'metadata' => 'array',
            'consumed_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }
}
