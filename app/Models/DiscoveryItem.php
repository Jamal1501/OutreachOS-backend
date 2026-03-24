<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscoveryItem extends Model
{
    use HasFactory;
    use HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'project_id',
        'discovery_run_id',
        'platform',
        'external_post_id',
        'handle',
        'username',
        'full_name',
        'profile_url',
        'post_url',
        'caption',
        'hashtags',
        'metrics',
        'duplicate_key',
        'recommended_action',
        'raw_payload',
        'discovered_at',
        'promoted_to_enrichment_at',
    ];

    protected function casts(): array
    {
        return [
            'hashtags' => 'array',
            'metrics' => 'array',
            'raw_payload' => 'array',
            'discovered_at' => 'datetime',
            'promoted_to_enrichment_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function discoveryRun(): BelongsTo
    {
        return $this->belongsTo(DiscoveryRun::class);
    }
}
