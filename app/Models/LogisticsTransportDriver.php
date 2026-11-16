<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A company transport person, never a user account or a pickup/delivery courier. */
class LogisticsTransportDriver extends Model
{
    protected $fillable = ['logistics_company_id', 'hub_id', 'name', 'phone', 'status'];

    public function scopeEligible(Builder $query): Builder
    {
        return $query->where('status', 'active')->whereHas('company', fn ($company) => $company->eligible())
            ->whereHas('hub', fn ($hub) => $hub->eligible()->whereColumn('logistics_hubs.logistics_company_id', 'logistics_transport_drivers.logistics_company_id'));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(LogisticsCompany::class, 'logistics_company_id');
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'hub_id');
    }
}
