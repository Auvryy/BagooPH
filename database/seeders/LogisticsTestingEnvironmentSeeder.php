<?php

namespace Database\Seeders;

use App\Models\Address;
use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryCheckpoint;
use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\MotherBag;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PickupClaim;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class LogisticsTestingEnvironmentSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            // 1. Reset all old order and delivery history so the user can test manually with a fresh clean slate
            DB::statement("SET session_replication_role = 'replica'");
            
            PickupClaim::query()->delete();
            DeliveryAttempt::query()->delete();
            DeliveryCheckpoint::query()->delete();
            MotherBag::query()->delete();
            LogisticsManifest::query()->delete();
            Delivery::query()->delete();
            OrderItem::query()->delete();
            Order::query()->delete();

            DB::statement("SET session_replication_role = 'origin'");

            // 2. Ensure ALL products have valid dimensions (length, width, height, weight) & stock
            // This prevents LogisticsRateService from throwing CheckoutException during calculation.
            Product::query()->update([
                'length_cm' => DB::raw('COALESCE(length_cm, 25.0)'),
                'width_cm' => DB::raw('COALESCE(width_cm, 18.0)'),
                'height_cm' => DB::raw('COALESCE(height_cm, 10.0)'),
                'weight_kg' => DB::raw('CASE WHEN weight_kg IS NULL OR weight_kg <= 0 THEN 0.50 ELSE weight_kg END'),
                'stock' => DB::raw('CASE WHEN stock IS NULL OR stock < 10 THEN 25 ELSE stock END'),
                'status' => 'active',
            ]);

            // Fix any products where length/width/height are 0
            Product::where('length_cm', '<=', 0)->update(['length_cm' => 25.0]);
            Product::where('width_cm', '<=', 0)->update(['width_cm' => 18.0]);
            Product::where('height_cm', '<=', 0)->update(['height_cm' => 10.0]);

            // 3. Configure the Primary Test Shop: Shop 15 "Artisan Pantry Gourmet" (Los Baños, Laguna)
            $sellerFood = User::firstOrCreate(
                ['email' => 'seller.food@bagoo.ph'],
                [
                    'name' => 'Artisan Pantry Gourmet Owner',
                    'role' => 'seller',
                    'status' => 'active',
                    'kyc_status' => 'approved',
                    'email_verified_at' => now(),
                    'phone' => '+639178822001',
                    'city' => 'Los Banos',
                    'address' => 'Lopez Avenue, Batong Malake',
                    'postal_code' => '4030',
                ]
            );
            $sellerFood->update([
                'password' => Hash::make('Password1234'),
                'status' => 'active',
                'kyc_status' => 'approved',
                'role' => 'seller',
            ]);

            $shop = Shop::where('user_id', $sellerFood->id)->first();
            if ($shop) {
                $shop->update([
                    'city' => 'Los Banos',
                    'pickup_province' => 'Laguna',
                    'pickup_postal_code' => '4030',
                    'status' => 'active',
                    'review_status' => 'approved',
                ]);
            }

            // 4. Configure Primary Test Buyer: "buyer@bagoo.test" (Santa Cruz, Laguna)
            $buyer = User::firstOrCreate(
                ['email' => 'buyer@bagoo.test'],
                [
                    'name' => 'Santa Cruz Buyer',
                    'role' => 'buyer',
                    'status' => 'active',
                    'kyc_status' => 'approved',
                    'email_verified_at' => now(),
                    'phone' => '+63 917 000 0002',
                    'address' => 'Pedro Guevara Avenue, Poblacion III',
                    'city' => 'Santa Cruz, Laguna',
                    'postal_code' => '4009',
                ]
            );
            $buyer->update([
                'password' => Hash::make('Password1234'),
                'status' => 'active',
                'kyc_status' => 'approved',
            ]);

            Address::updateOrCreate(
                ['user_id' => $buyer->id, 'is_default' => true],
                [
                    'recipient_name' => 'Santa Cruz Buyer',
                    'phone' => '+63 917 000 0002',
                    'street' => 'Pedro Guevara Avenue',
                    'barangay' => 'Poblacion III',
                    'city' => 'Santa Cruz',
                    'province' => 'Laguna',
                    'postal_code' => '4009',
                    'latitude' => 14.2789,
                    'longitude' => 121.4172,
                    'is_default' => true,
                ]
            );

            // 5. Connect and Verify Logistics Company BGX (Bagoo Express Dispatch Fleet)
            $company = LogisticsCompany::where('code', 'BGX')->firstOrFail();
            $company->update([
                'status' => 'active',
                'is_active' => true,
                'base_shipping_cents' => 6000,
                'included_weight_grams' => 1000,
                'additional_kg_cents' => 2000,
            ]);

            // Origin Bayan Hub: Los Baños (BH-LBN-01)
            $losBanosHub = LogisticsHub::where('code', 'BH-LBN-01')->firstOrFail();
            $losBanosHub->update([
                'is_active' => true,
                'province' => 'Laguna',
                'city_municipality' => 'Los Banos',
                'postal_codes' => ['4030'],
                'operating_hours' => 'Monday to Saturday, 8 AM to 6 PM',
            ]);

            // Regional Mother Hub: Laguna Regional DC (MH-LAG-01)
            $motherHub = LogisticsHub::where('code', 'MH-LAG-01')->firstOrFail();
            $motherHub->update([
                'is_active' => true,
                'province' => 'Laguna',
                'city_municipality' => 'Calamba City',
                'operating_hours' => '24/7 Trunkline Logistics Operations',
            ]);

            // Destination Bayan Hub: Santa Cruz (BH-SCZ-01)
            $santaCruzHub = LogisticsHub::where('code', 'BH-SCZ-01')->firstOrFail();
            $santaCruzHub->update([
                'is_active' => true,
                'province' => 'Laguna',
                'city_municipality' => 'Santa Cruz',
                'postal_codes' => ['4009'],
                'allows_self_pickup' => true,
                'operating_hours' => 'Monday to Saturday, 8 AM to 6 PM',
            ]);

            // Link parent hubs for proper linehaul routing
            $losBanosHub->update(['parent_hub_id' => $motherHub->id]);
            $santaCruzHub->update(['parent_hub_id' => $motherHub->id]);

            // 6. Accounts Setup with unified password 'Password1234'
            // A. Origin Hub Staff
            $losBanosHandler = User::where('email', 'losbanos.hub@bagoo.test')->first();
            if ($losBanosHandler) {
                $losBanosHandler->update(['password' => Hash::make('Password1234'), 'status' => 'active', 'kyc_status' => 'approved']);
                HubHandler::updateOrCreate(
                    ['user_id' => $losBanosHandler->id, 'hub_id' => $losBanosHub->id],
                    ['role_title' => 'Bayan Hub Operations Handler', 'is_active' => true]
                );
            }

            // B. Origin Pickup Rider
            $pickupRider = User::where('email', 'pickup.rider@bagoo.test')->first();
            if ($pickupRider) {
                $pickupRider->update(['password' => Hash::make('Password1234'), 'status' => 'active', 'kyc_status' => 'approved']);
                CourierProfile::updateOrCreate(
                    ['user_id' => $pickupRider->id],
                    [
                        'logistics_company_id' => $company->id,
                        'assigned_hub_id' => $losBanosHub->id,
                        'is_available' => true,
                        'or_cr_status' => 'Verified',
                        'vehicle_type' => 'Motorcycle',
                        'plate_number' => 'BG-LBN-101',
                    ]
                );
            }

            // C. Destination Hub Operator (Santa Cruz)
            $santaCruzHandler = User::where('email', 'logistics@bagoo.test')->first();
            if ($santaCruzHandler) {
                $santaCruzHandler->update(['password' => Hash::make('Password1234'), 'status' => 'active', 'kyc_status' => 'approved']);
                HubHandler::updateOrCreate(
                    ['user_id' => $santaCruzHandler->id, 'hub_id' => $santaCruzHub->id],
                    ['role_title' => 'Bayan Hub Operations Handler', 'is_active' => true]
                );
            }

            // D. Destination Final-Mile Delivery Rider (Santa Cruz)
            $deliveryRider = User::where('email', 'rider@bagoo.test')->first();
            if ($deliveryRider) {
                $deliveryRider->update(['password' => Hash::make('Password1234'), 'status' => 'active', 'kyc_status' => 'approved']);
                CourierProfile::updateOrCreate(
                    ['user_id' => $deliveryRider->id],
                    [
                        'logistics_company_id' => $company->id,
                        'assigned_hub_id' => $santaCruzHub->id,
                        'is_available' => true,
                        'or_cr_status' => 'Verified',
                        'vehicle_type' => 'Motorcycle',
                        'plate_number' => 'BG-SCZ-102',
                    ]
                );
            }
        });
    }
}
