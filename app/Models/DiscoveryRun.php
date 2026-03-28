<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DiscoveryRun extends Model
{
    use HasFactory;
    use HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'project_id',
        'platform',
        'provider',
        'provider_run_id',
        'provider_dataset_id',
        'status',
        'current_step',
        'hashtags',
        'discovery_limit',
        'enrichment_limit',
        'dedupe_against_crm',
        'request_payload',
        'result_payload',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'hashtags' => 'array',
            'dedupe_against_crm' => 'boolean',
            'request_payload' => 'array',
            'result_payload' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(DiscoveryItem::class);
    }

    public function enrichmentJobs(): HasMany
    {
        return $this->hasMany(EnrichmentJob::class);
    }
}
