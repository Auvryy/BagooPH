<?php

namespace App\Enums;

enum UserRole: string
{
    case ADMIN = 'admin';
    case SELLER = 'seller';
    case BUYER = 'buyer';
    case COURIER = 'courier';
    case LOGISTICS = 'logistics';

    public function label(): string
    {
        return match($this) {
            self::ADMIN => 'Administrator',
            self::SELLER => 'Seller / Merchant',
            self::BUYER => 'Buyer / Customer',
            self::COURIER => 'Courier / Rider',
            self::LOGISTICS => 'Logistics Partner',
        };
    }

    public function dashboardRoute(): string
    {
        return match($this) {
            self::ADMIN => 'admin.dashboard',
            self::SELLER => 'seller.dashboard',
            self::BUYER => 'marketplace',
            self::COURIER => 'courier.deliveries',
            self::LOGISTICS => 'admin.dashboard',
        };
    }
}
