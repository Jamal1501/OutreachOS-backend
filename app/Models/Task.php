<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    use HasFactory;
    use HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'project_id',
        'creator_profile_id',
        'message_template_id',
        'external_task_key',
        'platform',
        'handle',
        'task_type',
        'priority',
        'status',
        'due_at',
        'snoozed_until',
        'follow_up_count',
        'platform_connection_state',
        'group_key',
        'group_label',
        'group_type',
        'completion_outcome',
        'skip_reason',
        'skip_reason_detail',
        'snooze_reason',
        'actionable_channel',
        'external_channel',
        'conversation_url',
        'waiting_until',
        'open_url',
        'message_draft',
        'source_provider',
        'source_reference',
        'notes',
        'completed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'due_at'          => 'datetime',
            'snoozed_until'   => 'datetime',
            'waiting_until'    => 'datetime',
            'completed_at'    => 'datetime',
            'follow_up_count' => 'integer',
            'metadata'        => 'array',
        ];
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    /**
     * Open queue: not terminal, and not actively snoozed.
     */
    public function scopeVisible($query): void
    {
        $query->whereNotIn('status', ['COMPLETED', 'DONE', 'SKIPPED', 'ARCHIVED'])
              ->where(function ($q) {
                  $q->whereNull('snoozed_until')
                    ->orWhere('snoozed_until', '<=', now());
              });
    }

    /**
     * Currently snoozed (snooze has not expired).
     */
    public function scopeSnoozed($query): void
    {
        $query->where('status', 'SNOOZED')
              ->where('snoozed_until', '>', now());
    }

    /**
     * Failed/archived outreach candidates for the Cold Retry table.
     * Joined to creator_profiles so we can order by value_score.
     */
    public function scopeColdRetry($query): void
    {
        $query->where('tasks.status', 'ARCHIVED')
              ->whereNotNull('tasks.creator_profile_id')
              ->join('creator_profiles', 'creator_profiles.id', '=', 'tasks.creator_profile_id')
              ->whereNotNull('creator_profiles.value_score')
              ->orderByDesc('creator_profiles.value_score')
              ->select('tasks.*', 'creator_profiles.value_score as cp_value_score',
                       'creator_profiles.followers_count as cp_followers_count',
                       'creator_profiles.profile_pic_url as cp_profile_pic_url');
    }

    // ─── Relations ────────────────────────────────────────────────────────────

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creatorProfile(): BelongsTo
    {
        return $this->belongsTo(CreatorProfile::class);
    }

    public function messageTemplate(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class);
    }

    public function outreachEvents(): HasMany
    {
        return $this->hasMany(OutreachEvent::class);
    }
}
