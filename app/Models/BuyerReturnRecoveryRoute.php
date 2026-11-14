<?php

namespace App\Models;

use App\Models\Concerns\RetainsBuyerReturnEvidence;
use Illuminate\Database\Eloquent\Model;

class BuyerReturnRecoveryRoute extends Model
{
    use RetainsBuyerReturnEvidence;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['leg_index' => 'integer'];

    public function recovery()
    {
        return $this->belongsTo(BuyerReturnRecovery::class, 'recovery_id');
    }

    public function manifest()
    {
        return $this->belongsTo(LogisticsManifest::class, 'manifest_id');
    }
}
