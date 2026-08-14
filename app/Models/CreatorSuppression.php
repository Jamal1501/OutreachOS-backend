<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CreatorSuppression extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'platform',
        'normalized_handle',
        'email_hash',
        'source',
        'reason',
        'created_by_user_id',
    ];
}
