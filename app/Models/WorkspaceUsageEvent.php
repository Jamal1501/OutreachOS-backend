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
        'type',
        'credit_bucket',
        'units',
        'credit_cost',
        'provider',
        'provider_cost_usd',
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
            'metadata' => 'array',
            'consumed_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }
}
