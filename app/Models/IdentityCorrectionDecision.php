<?php

namespace App\Models;

use App\Models\Concerns\ImmutableGovernanceRecord;
use Illuminate\Database\Eloquent\Model;

class IdentityCorrectionDecision extends Model
{
    use ImmutableGovernanceRecord;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['review_token', 'before_state', 'after_state'];

    protected function casts(): array
    {
        return ['before_state' => 'array', 'after_state' => 'array', 'decided_at' => 'immutable_datetime'];
    }
}
