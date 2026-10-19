<?php

namespace App\Models;

use App\Models\Concerns\RetainsBuyerReturnEvidence;
use Illuminate\Database\Eloquent\Model;

class BuyerReturnAdjustment extends Model
{
    use RetainsBuyerReturnEvidence;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['gross_cents' => 'integer', 'commission_cents' => 'integer', 'shop_discount_cents' => 'integer',
        'platform_discount_cents' => 'integer', 'buyer_cents' => 'integer', 'seller_cents' => 'integer', 'platform_cents' => 'integer',
        'recovery_due_cents' => 'integer', 'unit_snapshot' => 'array', 'source_snapshot' => 'array', 'created_at' => 'datetime'];
}
