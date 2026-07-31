<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnrichmentJob extends Model
{
    use HasFactory;
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'project_id',
        'discovery_run_id',
        'platform',
        'provider',
        'provider_run_id',
        'provider_dataset_id',
        'status',
        'input_urls',
        'request_payload',
        'result_payload',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'input_urls' => 'array',
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

    public function discoveryRun(): BelongsTo
    {
        return $this->belongsTo(DiscoveryRun::class);
    }
}
