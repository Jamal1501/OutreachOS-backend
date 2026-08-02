<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportRequest extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'reference',
        'workspace_id',
        'user_id',
        'email',
        'category',
        'subject',
        'message',
        'page',
        'status',
        'delivery_attempts',
        'last_delivery_error',
        'sent_at',
        'ticket_status',
        'updated_by_operator_id',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'delivery_attempts' => 'integer',
            'sent_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
