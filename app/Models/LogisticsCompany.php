<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LogisticsCompany extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'code',
        'logo',
        'contact_email',
        'contact_phone',
        'address',
        'status',
        'accreditation_details',
        'is_active',
    ];

    protected $casts = [
        'accreditation_details' => 'array',
        'is_active' => 'boolean',
    ];

    public function attributesToArray(): array
    {
        $attributes = parent::attributesToArray();
        if (isset($attributes['accreditation_details'])) {
            unset($attributes['accreditation_details']['franchise_document_path'], $attributes['accreditation_details']['business_permit_path']);
        }

        return $attributes;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hubs(): HasMany
    {
        return $this->hasMany(LogisticsHub::class);
    }

    public function motherHubs(): HasMany
    {
        return $this->hasMany(LogisticsHub::class)->where('tier', 'regional_mother_hub');
    }

    public function bayanHubs(): HasMany
    {
        return $this->hasMany(LogisticsHub::class)->where('tier', 'local_bayan_hub');
    }

    public function fleet(): HasMany
    {
        return $this->hasMany(LogisticsFleet::class);
    }

    public function couriers(): HasMany
    {
        return $this->hasMany(CourierProfile::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }
}
