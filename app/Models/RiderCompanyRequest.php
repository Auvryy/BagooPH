<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class RiderCompanyRequest extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected $hidden = ['request_token', 'submission_fingerprint', 'company_snapshot', 'profile_snapshot'];

    protected function casts(): array
    {
        return ['company_snapshot' => 'array', 'profile_snapshot' => 'array', 'sharing_acknowledged' => 'boolean', 'intake_version' => 'integer', 'version' => 'integer', 'submitted_at' => 'immutable_datetime', 'withdrawn_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $request) {
            if ($request->isDirty(['reference', 'logistics_company_id', 'rider_id', 'request_token', 'submission_fingerprint', 'intake_version', 'company_snapshot', 'profile_snapshot', 'note', 'sharing_acknowledged', 'submitted_at'])) {
                throw new LogicException('Rider company submissions are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Rider company requests retain their history.'));
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rider_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(LogisticsCompany::class, 'logistics_company_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(RiderCompanyRequestEvent::class);
    }

    public function currentOffer(): BelongsTo
    {
        return $this->belongsTo(RiderCompanyOffer::class, 'current_offer_id');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(RiderCompanyOffer::class);
    }
}
