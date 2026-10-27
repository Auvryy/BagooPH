<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MotherBag extends Model
{
    use HasUuids;

    protected $attributes = ['status' => 'open', 'version' => 1];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'sealed_at' => 'immutable_datetime', 'loaded_at' => 'immutable_datetime', 'dispatched_at' => 'immutable_datetime', 'opened_at' => 'immutable_datetime'];
    }

    public function parcels() { return $this->hasMany(MotherBagParcel::class); }
    public function source() { return $this->belongsTo(LogisticsHub::class, 'source_hub_id'); }
    public function destination() { return $this->belongsTo(LogisticsHub::class, 'destination_hub_id'); }
    public function manifest() { return $this->belongsTo(LogisticsManifest::class); }
}
