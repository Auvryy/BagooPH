<?php

namespace App\Models;

use App\Models\Concerns\RetainsBuyerReturnEvidence;
use Illuminate\Database\Eloquent\Model;

class BuyerReturnCargoReading extends Model
{
    use RetainsBuyerReturnEvidence;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['profile' => 'array', 'route_position' => 'integer', 'created_at' => 'immutable_datetime'];
}
