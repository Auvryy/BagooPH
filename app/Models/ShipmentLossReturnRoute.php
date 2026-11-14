<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentLossReturnRoute extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'leg_index' => 'integer',
        'created_at' => 'immutable_datetime',
    ];

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(LogisticsManifest::class, 'manifest_id');
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(ShipmentLossReconciliation::class, 'reconciliation_id');
    }
}
