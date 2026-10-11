<?php

namespace App\Models;

class VoucherFundingEntry extends VoucherEvidence
{
    protected $casts = ['amount_cents' => 'integer'];
}
