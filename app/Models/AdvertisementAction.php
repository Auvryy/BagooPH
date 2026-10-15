<?php

namespace App\Models;

class AdvertisementAction extends AdvertisementEvidence
{
    protected $casts = ['before_state' => 'array', 'after_state' => 'array', 'source_version' => 'integer'];
}
