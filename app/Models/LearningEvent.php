<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LearningEvent extends Model
{
    use HasFactory;
    use HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'workspace_id',
        'project_id',
        'project_key',
        'source_type',
        'source_id',
        'event_name',
        'event_group',
        'occurred_at',
        'actor_user_id',
        'creator_profile_id',
        'task_id',
        'message_template_id',
        'platform',
        'handle',
        'channel',
        'outcome_label',
        'status',
        'creator_snapshot',
        'task_snapshot',
        'template_snapshot',
        'message_snapshot',
        'context',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'creator_snapshot' => 'array',
            'task_snapshot' => 'array',
            'template_snapshot' => 'array',
            'message_snapshot' => 'array',
            'context' => 'array',
            'metadata' => 'array',
        ];
    }
}
