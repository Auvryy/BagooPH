<?php

namespace App\Services;

use App\Models\CourierProfile;
use App\Models\LogisticsCompany;
use App\Models\User;
use App\Rules\UniqueAccountEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ApplicationRegistrationService
{
    public function register(array $account, array $details): User
    {
        return DB::transaction(function () use ($account, $details) {
            Validator::make($account, ['email' => [new UniqueAccountEmail]])->validate();
            $franchisePath = $account['_franchise_path'] ?? null;
            unset($account['_franchise_path']);
            if ($account['role'] === 'seller') {
                return app(SellerApplicationService::class)->register($account, $details['shop_name'], (int) $details['root_category_id']);
            }
            $user = User::create($account);
            if ($user->isCourier()) {
                CourierProfile::create(['user_id' => $user->id, ...array_intersect_key($details, array_flip(['vehicle_type', 'plate_number', 'license_number'])), 'or_cr_status' => 'Pending Verification', 'is_available' => false]);
            } elseif ($user->isLogistics()) {
                LogisticsCompany::create([
                    'user_id' => $user->id, 'name' => $details['company_name'],
                    'slug' => (Str::substr(Str::slug($details['company_name']), 0, 220) ?: 'company').'-'.$user->id,
                    'code' => $details['company_code'] ?? Str::upper(Str::random(12)),
                    'contact_email' => $details['company_email'], 'contact_phone' => $details['company_phone'], 'address' => $details['company_address'],
                    'status' => 'pending', 'is_active' => false,
                    'accreditation_details' => array_intersect_key($details, array_flip(['operating_province', 'franchise_number', 'fleet_size', 'vehicle_types'])) + [
                        'franchise_document_path' => $franchisePath,
                        'business_permit_path' => $account['business_permit_path'] ?? null,
                    ],
                ]);
            }

            return $user;
        });
    }
}
