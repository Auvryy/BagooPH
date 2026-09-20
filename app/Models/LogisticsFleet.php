<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogisticsFleet extends Model
{
    use HasFactory;

    protected $table = 'logistics_fleet';

    protected $fillable = [
        'logistics_company_id',
        'hub_id',
        'plate_number',
        'vehicle_type', // motorcycle, tricycle, l300_van, wing_truck
        'model',
        'capacity_kg',
        'assigned_driver_id',
        'status', // active, maintenance, idle
    ];

    protected $casts = [
        'capacity_kg' => 'float',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(LogisticsCompany::class, 'logistics_company_id');
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'hub_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_driver_id');
    }
}
