<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HubHandler extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'hub_id',
        'role_title',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'hub_id');
    }
}
