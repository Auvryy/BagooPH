<?php

namespace App\Models;

use App\Services\Commerce\PhilippineAddressCatalog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Address extends Model
{
    use HasFactory;

    protected $appends = ['region_name'];

    public function getRegionNameAttribute(): ?string
    {
        return app(PhilippineAddressCatalog::class)->regionName($this->region);
    }

    protected $fillable = [
        'user_id',
        'recipient_name',
        'phone',
        'region',
        'province',
        'city',
        'barangay',
        'street',
        'postal_code',
        'latitude',
        'longitude',
        'landmark',
        'verification_status', // verified, pending_review, unverified
        'verification_notes',
        'type',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getFormattedAddressAttribute(): string
    {
        $parts = array_filter([
            $this->street,
            $this->barangay,
            $this->city,
            $this->province,
            $this->postal_code,
        ]);

        return implode(', ', $parts);
    }
}
