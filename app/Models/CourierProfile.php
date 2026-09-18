<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourierProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'logistics_company_id',
        'assigned_hub_id',
        'assigned_barangay',
        'vehicle_id',
        'vehicle_type',
        'plate_number',
        'license_number',
        'or_cr_status',
        'is_available',
    ];

    protected $casts = [
        'is_available' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(LogisticsCompany::class, 'logistics_company_id');
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'assigned_hub_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(LogisticsFleet::class, 'vehicle_id');
    }
}
