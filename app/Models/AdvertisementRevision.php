<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvertisementRevision extends AdvertisementEvidence
{
    protected $casts = ['product_ids' => 'array', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime',
        'destination_id' => 'integer', 'offer_revision_id' => 'integer', 'imported_candidate' => 'boolean'];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(AdvertisementAsset::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(VoucherRevision::class, 'offer_revision_id');
    }
}
