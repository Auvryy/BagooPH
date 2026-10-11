<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoucherCampaignDraft extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['terms' => 'array'];
}
