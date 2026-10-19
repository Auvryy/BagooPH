<?php

namespace App\Models;

use App\Models\Concerns\RetainsBuyerReturnEvidence;
use Illuminate\Database\Eloquent\Model;

class BuyerReturnManifestRoute extends Model
{
    use RetainsBuyerReturnEvidence;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['leg_index' => 'integer', 'created_at' => 'datetime'];
}
