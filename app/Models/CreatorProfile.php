<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreatorProfile extends Model
{
    use HasFactory;
    use HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'creator_id',
        'project_id',
        'platform',
        'handle',
        'username',
        'profile_url',
        'dm_link',
        'profile_pic_url',
        'status',
        'lifecycle_state',
        'followers_count',
        'engagement_rate_pct',
        'preferred_channel',
        'last_content_at',
        'value_score',
        'value_bar',
        'duplicate_flag',
        'accepted_flag',
        'follow_up_needed',
        'dm_sent_at',
        'responded_at',
        'comment_attempted_at',
        'source_provider',
        'source_reference',
        'source_metadata',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'followers_count'      => 'integer',
            'engagement_rate_pct'  => 'decimal:2',
            'last_content_at'      => 'datetime',
            'value_score'          => 'integer',
            'accepted_flag'        => 'boolean',
            'follow_up_needed'     => 'boolean',
            'dm_sent_at'           => 'datetime',
            'responded_at'         => 'datetime',
            'comment_attempted_at' => 'datetime',
            'source_metadata'      => 'array',
            'last_synced_at'       => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Creator::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function outreachEvents(): HasMany
    {
        return $this->hasMany(OutreachEvent::class);
    }
}
