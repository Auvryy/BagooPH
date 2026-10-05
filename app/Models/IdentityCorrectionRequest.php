<?php

namespace App\Models;

use App\Models\Concerns\ImmutableGovernanceRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class IdentityCorrectionRequest extends Model
{
    use ImmutableGovernanceRecord;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['source_token', 'request_token', 'source', 'evidence'];

    protected function casts(): array
    {
        return ['source' => 'array', 'proposed' => 'array', 'evidence' => 'array', 'provenance' => 'array', 'requested_at' => 'immutable_datetime'];
    }

    public function decision(): HasOne
    {
        return $this->hasOne(IdentityCorrectionDecision::class, 'correction_request_id');
    }
}
