<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvertisementReview extends AdvertisementEvidence
{
    public function submission(): BelongsTo
    {
        return $this->belongsTo(AdvertisementSubmission::class, 'submission_id');
    }
}
