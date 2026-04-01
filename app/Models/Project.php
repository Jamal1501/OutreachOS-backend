<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'name',
        'workbook_id',
        'status',
        'settings',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'metadata' => 'array',
        ];
    }

    public function creators(): HasMany
    {
        return $this->hasMany(Creator::class);
    }

    public function creatorProfiles(): HasMany
    {
        return $this->hasMany(CreatorProfile::class);
    }

    public function discoveryRuns(): HasMany
    {
        return $this->hasMany(DiscoveryRun::class);
    }

    public function enrichmentJobs(): HasMany
    {
        return $this->hasMany(EnrichmentJob::class);
    }
}
