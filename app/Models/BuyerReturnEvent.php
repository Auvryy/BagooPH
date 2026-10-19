<?php

namespace App\Models;

use App\Models\Concerns\RetainsBuyerReturnEvidence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BuyerReturnEvent extends Model
{
    use RetainsBuyerReturnEvidence;

    public $timestamps = false;

    protected $guarded = ['id', 'reference', 'actor_role'];

    protected $hidden = ['request_token_hash', 'request_fingerprint'];

    protected $casts = ['sequence' => 'integer', 'context' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $event) {
            $event->reference = 'BRE-'.strtoupper((string) Str::uuid());
            $event->actor_role = User::whereKey($event->actor_id)->value('role');
            $event->created_at ??= now();
        });
    }
}
