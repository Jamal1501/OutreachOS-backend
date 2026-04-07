<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditPackage extends Model
{
    protected $table = 'credit_packages';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'scrape_credits',
        'ai_credits',
        'price_usd',
        'allowed_plan_ids',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'allowed_plan_ids' => 'array',
            'active' => 'boolean',
        ];
    }
}
