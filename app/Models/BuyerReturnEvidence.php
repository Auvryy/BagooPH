<?php

namespace App\Models;

use App\Models\Concerns\RetainsBuyerReturnEvidence;
use Illuminate\Database\Eloquent\Model;

class BuyerReturnEvidence extends Model
{
    use RetainsBuyerReturnEvidence;

    protected $table = 'buyer_return_evidence';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['path', 'content_hash'];

    protected $casts = ['bytes' => 'integer', 'created_at' => 'datetime'];
}
