<?php

namespace App\Models;

use App\Models\Concerns\RetainsWalletEvidence;
use Illuminate\Database\Eloquent\Model;

class WalletPaymentAttempt extends Model
{
    use RetainsWalletEvidence;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['request_token_hash'];

    protected $casts = ['created_at' => 'datetime'];
}
