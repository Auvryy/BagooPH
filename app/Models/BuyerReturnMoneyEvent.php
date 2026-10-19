<?php

namespace App\Models;

use App\Models\Concerns\RetainsBuyerReturnEvidence;
use Illuminate\Database\Eloquent\Model;

class BuyerReturnMoneyEvent extends Model
{
    use RetainsBuyerReturnEvidence;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['proof_path', 'proof_hash', 'request_token_hash', 'request_fingerprint'];

    protected $casts = ['amount_cents' => 'integer', 'sequence' => 'integer', 'context' => 'array', 'created_at' => 'datetime'];
}
