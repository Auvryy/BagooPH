<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class SaleEvent extends Model
{
    protected $fillable = ['slug', 'title', 'badge', 'banner_path', 'starts_at', 'ends_at', 'ended_at', 'created_by'];

    protected $casts = ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime'];

    public function vouchers(): HasMany
    {
        return $this->hasMany(SaleEventVoucher::class);
    }

    public function productSales(): HasMany
    {
        return $this->hasMany(ProductSale::class);
    }

    /** The moment the event actually stops: its end time, or earlier if an admin ended it. */
    public function closesAt(): CarbonImmutable
    {
        return $this->ended_at && $this->ended_at->lessThan($this->ends_at) ? $this->ended_at : $this->ends_at;
    }

    public function status(): string
    {
        if ($this->closesAt()->lessThanOrEqualTo(now())) {
            return 'ended';
        }

        return $this->starts_at->lessThanOrEqualTo(now()) ? 'live' : 'upcoming';
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_at')->where('ends_at', '>', now());
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->open()->where('starts_at', '<=', now());
    }

    public function bannerUrl(): ?string
    {
        if (! $this->banner_path) {
            return null;
        }

        return str_starts_with($this->banner_path, '/') ? $this->banner_path : Storage::disk('public')->url($this->banner_path);
    }
}
