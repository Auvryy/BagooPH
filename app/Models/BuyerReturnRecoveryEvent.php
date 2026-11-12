<?php

namespace App\Models;

use App\Models\Concerns\RetainsBuyerReturnEvidence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BuyerReturnRecoveryEvent extends Model
{
    use RetainsBuyerReturnEvidence;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['request_token', 'fingerprint'];

    protected $casts = ['context' => 'array', 'sequence' => 'integer', 'created_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $event) {
            $event->reference = (string) Str::uuid();
            $event->actor_role = User::whereKey($event->actor_id)->value('role');
        });
    }
}
