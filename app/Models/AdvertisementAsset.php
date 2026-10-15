<?php

namespace App\Models;

class AdvertisementAsset extends AdvertisementEvidence
{
    protected $casts = ['width' => 'integer', 'height' => 'integer', 'source_bytes' => 'integer', 'image_bytes' => 'integer'];
}
