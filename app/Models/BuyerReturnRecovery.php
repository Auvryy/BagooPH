<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

class BuyerReturnRecovery extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['source_state' => 'array', 'hub_ids' => 'array', 'version' => 'integer', 'leg_index' => 'integer'];

    protected static function booted(): void
    {
        static::creating(fn (self $record) => $record->reference = (string) Str::uuid());
        static::updating(function (self $record) {
            $mutable = ['state', 'version', 'leg_index', 'active_return_parcel_id', 'updated_at'];
            if ($record->getRawOriginal('authorized_by_id') === null) {
                $mutable = [...$mutable, 'authorized_by_id', 'authorization_reason'];
            }
            if (array_diff(array_keys($record->getDirty()), $mutable)) {
                throw new LogicException('The original return recovery authority must be retained.');
            }
        });
        static::deleting(fn () => throw new LogicException('Return recovery history must be retained.'));
    }

    public function parcel()
    {
        return $this->belongsTo(BuyerReturnParcel::class, 'buyer_return_parcel_id');
    }

    public function returnCase()
    {
        return $this->belongsTo(BuyerReturnCase::class, 'buyer_return_case_id');
    }

    public function originalMember()
    {
        return $this->belongsTo(BuyerReturnManifestParcel::class, 'original_member_id');
    }

    public function events()
    {
        return $this->hasMany(BuyerReturnRecoveryEvent::class, 'recovery_id')->orderBy('sequence');
    }

    public function routes()
    {
        return $this->hasMany(BuyerReturnRecoveryRoute::class, 'recovery_id')->orderBy('leg_index');
    }
}
