<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoucherRevisionBalance extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['redemption_count' => 'integer', 'committed_cents' => 'integer', 'spent_cents' => 'integer', 'released_cents' => 'integer'];
}
