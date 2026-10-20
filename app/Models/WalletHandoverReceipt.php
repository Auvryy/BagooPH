<?php

namespace App\Models;

use App\Models\Concerns\RetainsWalletEvidence;
use Illuminate\Database\Eloquent\Model;

class WalletHandoverReceipt extends Model
{
    use RetainsWalletEvidence;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['request_token_hash', 'fingerprint', 'proof_hash', 'recipient_name', 'recipient_relationship'];
}
