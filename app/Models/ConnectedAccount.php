<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectedAccount extends Model
{
    use HasFactory;
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'project_id',
        'platform',
        'provider',
        'external_account_id',
        'username',
        'status',
        'scopes',
        'credentials_reference',
        'oauth_credentials',
        'connected_by_user_id',
        'token_expires_at',
        'last_used_at',
        'last_error',
        'last_synced_at',
        'metadata',
    ];

    protected $hidden = [
        'oauth_credentials',
        'credentials_reference',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'oauth_credentials' => 'encrypted:array',
            'token_expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
