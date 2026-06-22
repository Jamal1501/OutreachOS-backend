<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DuplicateLink extends Model
{
    use HasUuids;

    protected $table = 'duplicate_links';

    public $incrementing = false;

    protected $keyType = 'string';

    const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'workspace_id',
        'project_id',
        'creator_a_handle',
        'creator_a_platform',
        'creator_b_handle',
        'creator_b_platform',
        'confidence',
        'match_signals',
        'status',
        'merged_at',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'match_signals' => 'array',
            'merged_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
