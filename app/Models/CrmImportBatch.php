<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmImportBatch extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'workspace_id',
        'project_id',
        'created_by_user_id',
        'original_filename',
        'status',
        'row_count',
        'summary',
        'settings',
        'activated_at',
        'rolled_back_at',
    ];

    protected function casts(): array
    {
        return [
            'row_count' => 'integer',
            'summary' => 'array',
            'settings' => 'array',
            'activated_at' => 'datetime',
            'rolled_back_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CrmImportBatchItem::class, 'batch_id');
    }
}
