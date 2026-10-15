<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Advertisement extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['version' => 'integer', 'archived_at' => 'immutable_datetime'];

    public function latestRevision(): HasOne
    {
        return $this->hasOne(AdvertisementRevision::class)->latestOfMany();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(AdvertisementSubmission::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(AdvertisementRevision::class);
    }

    public function publications(): HasMany
    {
        return $this->hasMany(AdvertisementPublication::class);
    }
}
