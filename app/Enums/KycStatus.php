<?php

namespace App\Enums;

enum KycStatus: string
{
    case PENDING_APPROVAL = 'pending_approval';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';

    public function label(): string
    {
        return match($this) {
            self::PENDING_APPROVAL => 'Pending Approval',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
        };
    }

    public function badgeClass(): string
    {
        return match($this) {
            self::PENDING_APPROVAL => 'bg-amber-50 text-amber-800 border-amber-200',
            self::APPROVED => 'bg-emerald-50 text-emerald-800 border-emerald-200',
            self::REJECTED => 'bg-rose-50 text-rose-800 border-rose-200',
        };
    }
}
