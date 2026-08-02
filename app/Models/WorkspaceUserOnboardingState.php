<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WorkspaceUserOnboardingState extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'workspace_id',
        'user_id',
        'version',
        'dismissed_routes',
        'hidden_at',
        'last_route',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'dismissed_routes' => 'array',
            'hidden_at' => 'datetime',
        ];
    }
}
