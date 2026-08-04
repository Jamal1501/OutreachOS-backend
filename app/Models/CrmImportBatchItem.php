<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmImportBatchItem extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'batch_id',
        'creator_id',
        'creator_profile_id',
        'message_template_id',
        'action',
        'creator_before',
        'profile_before',
        'template_before',
        'template_after',
    ];

    protected function casts(): array
    {
        return [
            'creator_before' => 'array',
            'profile_before' => 'array',
            'template_before' => 'array',
            'template_after' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CrmImportBatch::class, 'batch_id');
    }
}
