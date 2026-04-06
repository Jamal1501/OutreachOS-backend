<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkspaceCreditWallet extends Model
{
    protected $table = 'workspace_credit_wallets';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'workspace_id',
        'scrape_credits_balance',
        'ai_credits_balance',
        'bonus_scrape_credits',
        'bonus_ai_credits',
        'lifetime_scrape_used',
        'lifetime_ai_used',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
