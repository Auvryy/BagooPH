<?php

namespace App\Services\Logistics;

use App\Exceptions\HandoverHoldException;
use App\Models\Delivery;
use App\Models\HandoverIssue;

class HandoverHoldService
{
    public function active(Delivery $parcel): ?HandoverIssue
    {
        return HandoverIssue::where('active_delivery_id', $parcel->id)->where('state', 'needs_recovery')->first();
    }

    public function held(Delivery $parcel): bool
    {
        return HandoverIssue::where('active_delivery_id', $parcel->id)->where('state', 'needs_recovery')->exists();
    }

    public function assertMovable(Delivery $parcel): void
    {
        if ($this->held($parcel)) {
            throw new HandoverHoldException;
        }
    }
}
