<?php

namespace App\Models;

use App\Models\Concerns\RetainsWalletEvidence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WalletTransaction extends Model
{
    use RetainsWalletEvidence;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['request_token_hash', 'fingerprint', 'business_key'];

    protected $casts = ['amount_cents' => 'integer', 'context' => 'array', 'created_at' => 'datetime'];

    public function entries(): HasMany
    {
        return $this->hasMany(WalletEntry::class);
    }
}
