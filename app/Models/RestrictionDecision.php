<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class RestrictionDecision extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['subject_type', 'subject_id', 'actor_id', 'actor_role', 'actor_name', 'source_token', 'action', 'reason', 'before_state', 'after_state', 'decided_at'];

    protected $hidden = ['source_token', 'before_state', 'after_state'];

    protected function casts(): array
    {
        return ['before_state' => 'array', 'after_state' => 'array', 'decided_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Restriction decisions are immutable.'));
        static::deleting(fn () => throw new LogicException('Restriction decisions are immutable.'));
    }

    public function affectedWork(): HasMany
    {
        return $this->hasMany(RestrictionAffectedWork::class);
    }
}
