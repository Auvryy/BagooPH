<?php

namespace App\Models;

use App\Models\Concerns\RetainsBuyerReturnEvidence;
use Illuminate\Database\Eloquent\Model;

class BuyerReturnItem extends Model
{
    use RetainsBuyerReturnEvidence;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['quantity' => 'integer', 'unit_positions' => 'array', 'purchase_snapshot' => 'array'];
}
