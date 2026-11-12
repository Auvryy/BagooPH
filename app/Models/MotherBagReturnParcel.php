<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MotherBagReturnParcel extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['source_state' => 'array', 'handling_snapshot' => 'array', 'removed_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime'];

    public function bag()
    {
        return $this->belongsTo(MotherBag::class, 'mother_bag_id');
    }

    public function parcel()
    {
        return $this->belongsTo(BuyerReturnParcel::class, 'buyer_return_parcel_id');
    }
}
