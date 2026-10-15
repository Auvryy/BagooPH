<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AdvertisementSubmission extends AdvertisementEvidence
{
    protected $casts = ['revision_id' => 'integer', 'advertisement_id' => 'integer', 'submitted_by_id' => 'integer', 'shop_scope' => 'array'];

    public function advertisement(): BelongsTo
    {
        return $this->belongsTo(Advertisement::class);
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(AdvertisementRevision::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(AdvertisementReview::class, 'submission_id');
    }

    public function latestReview(): HasOne
    {
        return $this->hasOne(AdvertisementReview::class, 'submission_id')->latestOfMany();
    }

    public function state(): string
    {
        return $this->latestReview?->decision ?? 'pending';
    }
}
