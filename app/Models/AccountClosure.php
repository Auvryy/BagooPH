<?php

namespace App\Models;

use App\Models\Concerns\ImmutableGovernanceRecord;
use Illuminate\Database\Eloquent\Model;

class AccountClosure extends Model
{
    use ImmutableGovernanceRecord;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['source_token', 'before_state'];

    protected function casts(): array
    {
        return ['before_state' => 'array', 'closed_at' => 'datetime'];
    }
}
