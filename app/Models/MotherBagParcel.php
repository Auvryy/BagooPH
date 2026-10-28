<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MotherBagParcel extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['source_state' => 'array', 'received_at' => 'immutable_datetime'];
    }

    public function delivery()
    {
        return $this->belongsTo(Delivery::class);
    }

    public function bag()
    {
        return $this->belongsTo(MotherBag::class, 'mother_bag_id');
    }
}
