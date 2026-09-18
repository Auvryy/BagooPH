<?php

namespace Database\Seeders;

use App\Models\Address;
use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\Logistics\OrderStateMachineService;
use Illuminate\Database\Seeder;

class LogisticsNetworkSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('email', 'admin@bagoo.test')->first();
        $buyer = User::where('email', 'buyer@bagoo.test')->first();
        $seller = User::where('email', 'seller@bagoo.test')->first();
        $courier = User::where('email', 'rider@bagoo.test')->first();
        $logisticsUser = User::where('email', 'logistics@bagoo.test')->first();
        $shop = Shop::where('user_id', $seller?->id)->first();

        // 1. Multi-Tenant Logistics Companies
        $bgxCompany = LogisticsCompany::updateOrCreate(
            ['code' => 'BGX'],
            [
                'user_id'              => $admin?->id,
                'name'                 => 'Bagoo Express Dispatch Fleet',
                'slug'                 => 'bagoo-express-dispatch-fleet',
                'contact_email'        => 'dispatch@bagooph.shop',
                'contact_phone'        => '+63 917 888 2246',
                'address'              => 'Bagoo Central Dispatch, C5 Freight Corridor, Pasig City',
                'status'               => 'active',
                'is_active'            => true,
                'accreditation_details' => [
                    'license_type'     => 'Nationwide Road Freight Operator',
                    'franchise_number' => 'LTFRB-2026-BGX-9901',
                    'fleet_size'       => 85,
                ],
            ]
        );

        $jntCompany = LogisticsCompany::updateOrCreate(
            ['code' => 'JNT'],
            [
                'user_id'              => $admin?->id,
                'name'                 => 'J&T Express Philippines',
                'slug'                 => 'jt-express-philippines',
                'contact_email'        => 'support@jtexpress.ph',
                'contact_phone'        => '+63 2 8911 1888',
                'address'              => 'J&T Gateway Tower, Ortigas Center, Pasig City',
                'status'               => 'active',
                'is_active'            => true,
                'accreditation_details' => [
                    'license_type'     => 'Integrated Express Logistics',
                    'franchise_number' => 'LTFRB-2026-JNT-4412',
                    'fleet_size'       => 350,
                ],
            ]
        );

        // 2. Hub Network Hierarchy (Regional Mother Hubs & Local Bayan Hubs)
        // A. Laguna Regional Mother Hub
        $lagunaMotherHub = LogisticsHub::updateOrCreate(
            ['code' => 'MH-LAG-01'],
            [
                'logistics_company_id' => $bgxCompany->id,
                'name'                 => 'Laguna Regional Mother Hub',
                'tier'                 => 'regional_mother_hub',
                'province'             => 'Laguna',
                'city_municipality'    => 'Calamba City',
                'barangay'             => 'Real',
                'address'              => 'KM 54 National Highway, Real, Calamba City, Laguna',
                'latitude'             => 14.2078,
                'longitude'            => 121.1558,
                'capacity'             => 15000,
                'coverage_barangays'   => ['Calamba Real', 'Turbina', 'Canlubang', 'Parian', 'Halang', 'Bucal'],
                'allows_self_pickup'   => false,
                'is_active'            => true,
            ]
        );

        // B. Metro Manila Regional Sortation Center
        $manilaMotherHub = LogisticsHub::updateOrCreate(
            ['code' => 'MH-MNL-01'],
            [
                'logistics_company_id' => $bgxCompany->id,
                'name'                 => 'Metro Manila Sortation Center',
                'tier'                 => 'regional_mother_hub',
                'province'             => 'Metro Manila',
                'city_municipality'    => 'Taguig City',
                'barangay'             => 'Western Bicutan',
                'address'              => 'FTI Complex, East Service Road, Western Bicutan, Taguig, Metro Manila',
                'latitude'             => 14.5098,
                'longitude'            => 121.0374,
                'capacity'             => 30000,
                'coverage_barangays'   => ['Western Bicutan', 'Fort Bonifacio', 'Pinagsama', 'Ususan', 'Bagumbayan'],
                'allows_self_pickup'   => false,
                'is_active'            => true,
            ]
        );

        // C. Local Bayan Hub 1: Santa Cruz Bayan Hub
        $santaCruzHub = LogisticsHub::updateOrCreate(
            ['code' => 'BH-SCZ-01'],
            [
                'logistics_company_id' => $bgxCompany->id,
                'name'                 => 'Santa Cruz Bayan Hub',
                'tier'                 => 'local_bayan_hub',
                'province'             => 'Laguna',
                'city_municipality'    => 'Santa Cruz',
                'barangay'             => 'Poblacion III',
                'address'              => 'Pedro Guevara Avenue, Poblacion III, Santa Cruz, Laguna',
                'latitude'             => 14.2789,
                'longitude'            => 121.4172,
                'capacity'             => 2500,
                'coverage_barangays'   => [
                    'Poblacion I',
                    'Poblacion II',
                    'Poblacion III',
                    'Poblacion IV',
                    'Pagsawitan',
                    'Bubukal',
                    'Labuin',
                    'Gatid',
                    'Bagumbayan',
                    'Calios',
                    'Patimbao',
                    'Santisima Cruz',
                    'San Jose',
                    'San Pablo Sur',
                ],
                'allows_self_pickup'   => true,
                'is_active'            => true,
            ]
        );

        // D. Local Bayan Hub 2: Pagsanjan Bayan Hub
        $pagsanjanHub = LogisticsHub::updateOrCreate(
            ['code' => 'BH-PGS-01'],
            [
                'logistics_company_id' => $bgxCompany->id,
                'name'                 => 'Pagsanjan Bayan Hub',
                'tier'                 => 'local_bayan_hub',
                'province'             => 'Laguna',
                'city_municipality'    => 'Pagsanjan',
                'barangay'             => 'Poblacion I',
                'address'              => 'J.P. Rizal St, Poblacion I, Pagsanjan, Laguna',
                'latitude'             => 14.2736,
                'longitude'            => 121.4556,
                'capacity'             => 1800,
                'coverage_barangays'   => [
                    'Poblacion I',
                    'Poblacion II',
                    'Sampaloc',
                    'Biñan',
                    'Dingin',
                    'Magdapio',
                    'Pinagsanjan',
                    'Lambac',
                ],
                'allows_self_pickup'   => true,
                'is_active'            => true,
            ]
        );

        // E. Local Bayan Hub 3: Los Baños Bayan Hub
        $losBanosHub = LogisticsHub::updateOrCreate(
            ['code' => 'BH-LBN-01'],
            [
                'logistics_company_id' => $bgxCompany->id,
                'name'                 => 'Los Baños Bayan Hub',
                'tier'                 => 'local_bayan_hub',
                'province'             => 'Laguna',
                'city_municipality'    => 'Los Baños',
                'barangay'             => 'Batong Malake',
                'address'              => 'Lopez Avenue, Batong Malake, Los Baños, Laguna',
                'latitude'             => 14.1678,
                'longitude'            => 121.2435,
                'capacity'             => 2200,
                'coverage_barangays'   => [
                    'Batong Malake',
                    'Bayog',
                    'Anos',
                    'San Antonio',
                    'Malinta',
                    'Mayndon',
                    'Timugan',
                    'Tuntungin-Putho',
                    'Bambang',
                ],
                'allows_self_pickup'   => true,
                'is_active'            => true,
            ]
        );

        // F. Local Bayan Hub 4: San Pablo Bayan Hub
        $sanPabloHub = LogisticsHub::updateOrCreate(
            ['code' => 'BH-SPB-01'],
            [
                'logistics_company_id' => $bgxCompany->id,
                'name'                 => 'San Pablo Bayan Hub',
                'tier'                 => 'local_bayan_hub',
                'province'             => 'Laguna',
                'city_municipality'    => 'San Pablo City',
                'barangay'             => 'San Rafael',
                'address'              => 'Maharlika Highway, San Rafael, San Pablo City, Laguna',
                'latitude'             => 14.0722,
                'longitude'            => 121.3255,
                'capacity'             => 3000,
                'coverage_barangays'   => [
                    'San Rafael',
                    'San Roque',
                    'Concepcion',
                    'San Gabriel',
                    'Del Remedio',
                    'San Nicolas',
                ],
                'allows_self_pickup'   => true,
                'is_active'            => true,
            ]
        );

        // G. J&T Express South Luzon Gateway Hub
        $jntMotherHub = LogisticsHub::updateOrCreate(
            ['code' => 'JNT-MH-SLZ'],
            [
                'logistics_company_id' => $jntCompany->id,
                'name'                 => 'J&T South Luzon Gateway Hub',
                'tier'                 => 'regional_mother_hub',
                'province'             => 'Laguna',
                'city_municipality'    => 'Biñan City',
                'barangay'             => 'Mamplasan',
                'address'              => 'Laguna Technopark, Mamplasan, Biñan City, Laguna',
                'latitude'             => 14.3025,
                'longitude'            => 121.0820,
                'capacity'             => 25000,
                'coverage_barangays'   => ['Mamplasan', 'San Francisco', 'Santo Niño', 'De La Paz'],
                'allows_self_pickup'   => false,
                'is_active'            => true,
            ]
        );

        // H. J&T Express Santa Cruz Branch Hub
        $jntBayanHub = LogisticsHub::updateOrCreate(
            ['code' => 'JNT-BH-SCZ'],
            [
                'logistics_company_id' => $jntCompany->id,
                'name'                 => 'J&T Santa Cruz Branch Hub',
                'tier'                 => 'local_bayan_hub',
                'province'             => 'Laguna',
                'city_municipality'    => 'Santa Cruz',
                'barangay'             => 'Pagsawitan',
                'address'              => 'National Highway, Pagsawitan, Santa Cruz, Laguna',
                'latitude'             => 14.2715,
                'longitude'            => 121.4120,
                'capacity'             => 2000,
                'coverage_barangays'   => ['Pagsawitan', 'Bubukal', 'Labuin', 'Gatid', 'Bagumbayan'],
                'allows_self_pickup'   => true,
                'is_active'            => true,
            ]
        );

        // 3. Multi-Tier Vehicle Fleet Hierarchy
        // Tier 1: Last-mile delivery (Motorcycle)
        $motorcycle1 = LogisticsFleet::updateOrCreate(
            ['plate_number' => 'BG-MTR-101'],
            [
                'logistics_company_id' => $bgxCompany->id,
                'hub_id'               => $santaCruzHub->id,
                'vehicle_type'         => 'motorcycle',
                'model'                => 'Honda TMX 125 with Insulated Delivery Box (30 parcels)',
                'capacity_kg'          => 75.00,
                'assigned_driver_id'   => $courier?->id,
                'status'               => 'active',
            ]
        );

        LogisticsFleet::updateOrCreate(
            ['plate_number' => 'BG-MTR-102'],
            [
                'logistics_company_id' => $bgxCompany->id,
                'hub_id'               => $losBanosHub->id,
                'vehicle_type'         => 'motorcycle',
                'model'                => 'Yamaha Sight 115 Last-Mile Courier (30 parcels)',
                'capacity_kg'          => 70.00,
                'status'               => 'active',
            ]
        );

        // Tier 2: Mid-mile Bayan feeder shuttle (L300 / Cargo Van)
        LogisticsFleet::updateOrCreate(
            ['plate_number' => 'BG-L300-201'],
            [
                'logistics_company_id' => $bgxCompany->id,
                'hub_id'               => $lagunaMotherHub->id,
                'vehicle_type'         => 'l300_van',
                'model'                => 'Mitsubishi L300 FB Van (400 Parcels Feeder Shuttle)',
                'capacity_kg'          => 1200.00,
                'status'               => 'active',
            ]
        );

        LogisticsFleet::updateOrCreate(
            ['plate_number' => 'BG-VAN-202'],
            [
                'logistics_company_id' => $bgxCompany->id,
                'hub_id'               => $manilaMotherHub->id,
                'vehicle_type'         => 'l300_van',
                'model'                => 'Nissan NV350 Urvan High-Roof Feeder (350 Parcels)',
                'capacity_kg'          => 1100.00,
                'status'               => 'active',
            ]
        );

        // Tier 3: Trunk line-haul highway freight (10-Wheeler Wing Truck)
        LogisticsFleet::updateOrCreate(
            ['plate_number' => 'BG-WNG-301'],
            [
                'logistics_company_id' => $bgxCompany->id,
                'hub_id'               => $lagunaMotherHub->id,
                'vehicle_type'         => 'wing_truck',
                'model'                => 'Isuzu Giga 10-Wheeler Wing Van 32-Foot Line-Haul (2,500 Parcels)',
                'capacity_kg'          => 15000.00,
                'status'               => 'active',
            ]
        );

        // 4. Hub Handlers
        if ($logisticsUser) {
            HubHandler::updateOrCreate(
                ['user_id' => $logisticsUser->id],
                [
                    'hub_id'     => $santaCruzHub->id,
                    'role_title' => 'Floor Intake & Counter Lead Specialist',
                    'is_active'  => true,
                ]
            );
        }

        // 5. Courier Profile Link
        if ($courier) {
            CourierProfile::updateOrCreate(
                ['user_id' => $courier->id],
                [
                    'logistics_company_id' => $bgxCompany->id,
                    'assigned_hub_id'      => $santaCruzHub->id,
                    'vehicle_type'         => 'Motorcycle',
                    'plate_number'         => 'BG-MTR-101',
                    'license_number'       => 'N01-26-888999',
                    'or_cr_status'         => 'Verified',
                    'is_available'         => true,
                ]
            );
        }

        // 6. Test Orders with Complete Road Freight Facility Routes
        $product1 = Product::where('slug', 'vanguard-commuter-backpack-26l')->first();
        $product2 = Product::where('slug', 'nomad-modular-crossbody-tech-pouch')->first();

        if ($buyer && $shop && $product1 && $product2) {
            // Seed Buyer Default Saved Address with GPS Coordinates
            $buyerAddress = Address::updateOrCreate(
                ['user_id' => $buyer->id, 'recipient_name' => 'Test Buyer'],
                [
                    'phone'       => '+63 917 000 0002',
                    'province'    => 'Laguna',
                    'city'        => 'Los Baños',
                    'barangay'    => 'Batong Malake',
                    'street'      => 'Door 3, Lopez Commercial Building, Lopez Avenue',
                    'postal_code' => '4030',
                    'latitude'    => 14.1678,
                    'longitude'   => 121.2435,
                    'landmark'    => 'Beside UPLB Vega Center Gate',
                    'is_default'  => true,
                ]
            );

            // ----------------------------------------------------
            // SAMPLE ORDER 1: Standard Doorstep Delivery
            // Facility Hops: Shop -> Santa Cruz Hub -> Laguna Mother Hub -> Los Baños Hub -> Barangay Bin
            // ----------------------------------------------------
            $order1 = Order::updateOrCreate(
                ['order_number' => 'BG-ORD-2026-0001'],
                [
                    'buyer_id'             => $buyer->id,
                    'subtotal'             => $product1->price,
                    'shipping_fee'         => 60.00,
                    'total_amount'         => $product1->price + 60.00,
                    'status'               => OrderStateMachineService::STATUS_SORTED_TO_BARANGAY_BIN,
                    'payment_method'       => 'cod',
                    'payment_status'       => 'unpaid',
                    'recipient_name'       => $buyer->name,
                    'recipient_phone'      => $buyer->phone ?? '+63 917 000 0002',
                    'shipping_address'     => $buyerAddress->street,
                    'shipping_city'        => 'Los Baños',
                    'shipping_postal_code' => '4030',
                    'destination_barangay' => 'Batong Malake',
                    'notes'                => 'Beside UPLB Vega Center Gate',
                    'delivery_type'        => 'doorstep',
                ]
            );

            OrderItem::firstOrCreate(
                ['order_id' => $order1->id, 'product_id' => $product1->id],
                [
                    'shop_id'    => $shop->id,
                    'quantity'   => 1,
                    'unit_price' => $product1->price,
                    'subtotal'   => $product1->price,
                ]
            );

            $delivery1 = Delivery::updateOrCreate(
                ['tracking_number' => 'BG-TRK-7701-PH'],
                [
                    'order_id'                  => $order1->id,
                    'courier_id'                => $courier?->id,
                    'logistics_company_id'      => $bgxCompany->id,
                    'logistics_partner'         => $bgxCompany->name,
                    'status'                    => OrderStateMachineService::STATUS_SORTED_TO_BARANGAY_BIN,
                    'delivery_type'             => 'doorstep',
                    'origin_bayan_hub_id'       => $santaCruzHub->id,
                    'origin_mother_hub_id'      => $lagunaMotherHub->id,
                    'destination_mother_hub_id' => $lagunaMotherHub->id,
                    'destination_bayan_hub_id'  => $losBanosHub->id,
                    'current_hub_id'            => $losBanosHub->id,
                    'destination_bin'           => 'BIN: BRGY-BATONG-MALAKE',
                    'assigned_rider_id'         => $courier?->id,
                    'shuttle_manifest_number'   => 'SHUTTLE-20260918-0412',
                    'truck_manifest_number'     => 'LINEHAUL-20260918-0988',
                    'pickup_store_name'         => $shop->name,
                    'pickup_address'            => $shop->address . ', ' . $shop->city,
                    'pickup_phone'              => $shop->phone ?? '+63 917 000 0003',
                    'delivery_address'          => $buyerAddress->street . ', Los Baños, Laguna',
                    'delivery_recipient_name'   => $buyer->name,
                    'delivery_phone'            => $buyer->phone ?? '+63 917 000 0002',
                    'picked_up_at'              => now()->subHours(6),
                ]
            );

            // Audit Trail Checkpoints for Order 1
            $checkpointsData1 = [
                [
                    'checkpoint_type' => 'picked_up',
                    'location_name'   => 'Seller Warehouse (Pasig City)',
                    'notes'           => 'Parcel collected from Apex Gear & Studio merchant depot.',
                    'time_offset'     => 6,
                    'hub_id'          => null,
                ],
                [
                    'checkpoint_type' => 'arrived_at_origin_hub',
                    'location_name'   => 'Santa Cruz Bayan Hub (BH-SCZ-01)',
                    'notes'           => 'Scanned at Bayan Hub Floor Intake. Parcel weight verified: 1.10kg.',
                    'time_offset'     => 5,
                    'hub_id'          => $santaCruzHub->id,
                ],
                [
                    'checkpoint_type' => 'in_transit_to_mother_hub',
                    'location_name'   => 'Santa Cruz Bayan Hub Loading Dock',
                    'notes'           => 'Loaded to Feeder Shuttle (BG-L300-201) bound for Laguna Regional Mother Hub.',
                    'time_offset'     => 4,
                    'hub_id'          => $santaCruzHub->id,
                ],
                [
                    'checkpoint_type' => 'arrived_at_mother_hub',
                    'location_name'   => 'Laguna Regional Mother Hub (MH-LAG-01)',
                    'notes'           => 'Received at Calamba Regional Mother Hub high-speed cross-dock.',
                    'time_offset'     => 3,
                    'hub_id'          => $lagunaMotherHub->id,
                ],
                [
                    'checkpoint_type' => 'sorted_to_line_haul',
                    'location_name'   => 'Calamba Regional Sortation Bay 4',
                    'notes'           => 'Sorted to Line-Haul Highway Freight (BG-WNG-301) for Los Baños Bayan Hub route.',
                    'time_offset'     => 2,
                    'hub_id'          => $lagunaMotherHub->id,
                ],
                [
                    'checkpoint_type' => 'arrived_at_destination_hub',
                    'location_name'   => 'Los Baños Bayan Hub (BH-LBN-01)',
                    'notes'           => 'Received at destination Bayan Hub facility.',
                    'time_offset'     => 1,
                    'hub_id'          => $losBanosHub->id,
                ],
                [
                    'checkpoint_type' => 'sorted_to_barangay_bin',
                    'location_name'   => 'Los Baños Bayan Hub Sorting Bay',
                    'notes'           => 'Staged in BIN: BRGY-BATONG-MALAKE. Assigned to Last-Mile Rider: Test Rider.',
                    'time_offset'     => 0.2,
                    'hub_id'          => $losBanosHub->id,
                ],
            ];

            foreach ($checkpointsData1 as $cp) {
                DeliveryCheckpoint::updateOrCreate(
                    [
                        'delivery_id'     => $delivery1->id,
                        'checkpoint_type' => $cp['checkpoint_type'],
                    ],
                    [
                        'location_name'   => $cp['location_name'],
                        'notes'           => $cp['notes'],
                        'barcode_scanned' => $delivery1->tracking_number,
                        'scanned_by_id'   => $logisticsUser?->id,
                        'hub_id'          => $cp['hub_id'],
                        'created_at'      => now()->subHours($cp['time_offset']),
                    ]
                );
            }

            // ----------------------------------------------------
            // SAMPLE ORDER 2: Free Bayan Hub Self-Pickup
            // Staged at Customer Service Counter at Santa Cruz Bayan Hub
            // ----------------------------------------------------
            $order2 = Order::updateOrCreate(
                ['order_number' => 'BG-ORD-2026-0002'],
                [
                    'buyer_id'             => $buyer->id,
                    'subtotal'             => $product2->price,
                    'shipping_fee'         => 0.00, // 100% Free Bayan Hub Self-Pickup
                    'total_amount'         => $product2->price,
                    'status'               => OrderStateMachineService::STATUS_READY_FOR_HUB_PICKUP,
                    'payment_method'       => 'cod',
                    'payment_status'       => 'unpaid',
                    'recipient_name'       => $buyer->name,
                    'recipient_phone'      => $buyer->phone ?? '+63 917 000 0002',
                    'shipping_address'     => 'Pedro Guevara Avenue, Poblacion III, Santa Cruz, Laguna',
                    'shipping_city'        => 'Santa Cruz',
                    'shipping_postal_code' => '4009',
                    'destination_barangay' => 'Poblacion III',
                    'notes'                => 'Claim at Bayan Hub Front Counter',
                    'delivery_type'        => 'hub_self_pickup',
                    'pickup_hub_id'        => $santaCruzHub->id,
                ]
            );

            OrderItem::firstOrCreate(
                ['order_id' => $order2->id, 'product_id' => $product2->id],
                [
                    'shop_id'    => $shop->id,
                    'quantity'   => 1,
                    'unit_price' => $product2->price,
                    'subtotal'   => $product2->price,
                ]
            );

            $delivery2 = Delivery::updateOrCreate(
                ['tracking_number' => 'BG-TRK-8802-PH'],
                [
                    'order_id'                  => $order2->id,
                    'logistics_company_id'      => $bgxCompany->id,
                    'logistics_partner'         => $bgxCompany->name,
                    'status'                    => OrderStateMachineService::STATUS_READY_FOR_HUB_PICKUP,
                    'delivery_type'             => 'hub_self_pickup',
                    'origin_bayan_hub_id'       => $santaCruzHub->id,
                    'origin_mother_hub_id'      => $lagunaMotherHub->id,
                    'destination_mother_hub_id' => $lagunaMotherHub->id,
                    'destination_bayan_hub_id'  => $santaCruzHub->id,
                    'current_hub_id'            => $santaCruzHub->id,
                    'destination_bin'           => 'STAGE: SELF-PICKUP-SHELF-A1',
                    'pickup_store_name'         => $shop->name,
                    'pickup_address'            => $shop->address . ', ' . $shop->city,
                    'pickup_phone'              => $shop->phone ?? '+63 917 000 0003',
                    'delivery_address'          => 'Pedro Guevara Avenue, Poblacion III, Santa Cruz, Laguna',
                    'delivery_recipient_name'   => $buyer->name,
                    'delivery_phone'            => $buyer->phone ?? '+63 917 000 0002',
                    'picked_up_at'              => now()->subHours(8),
                ]
            );

            $checkpointsData2 = [
                [
                    'checkpoint_type' => 'picked_up',
                    'location_name'   => 'Apex Gear & Studio Warehouse',
                    'notes'           => 'Merchant dispatch handover completed.',
                    'time_offset'     => 8,
                    'hub_id'          => null,
                ],
                [
                    'checkpoint_type' => 'arrived_at_destination_hub',
                    'location_name'   => 'Santa Cruz Bayan Hub (BH-SCZ-01)',
                    'notes'           => 'Parcel arrived at destination Bayan Hub facility.',
                    'time_offset'     => 2,
                    'hub_id'          => $santaCruzHub->id,
                ],
                [
                    'checkpoint_type' => 'ready_for_hub_pickup',
                    'location_name'   => 'Santa Cruz Bayan Hub Counter Shelf A1',
                    'notes'           => 'Staged at customer counter. Free Self-Pickup Claim notification sent to buyer.',
                    'time_offset'     => 1,
                    'hub_id'          => $santaCruzHub->id,
                ],
            ];

            foreach ($checkpointsData2 as $cp) {
                DeliveryCheckpoint::updateOrCreate(
                    [
                        'delivery_id'     => $delivery2->id,
                        'checkpoint_type' => $cp['checkpoint_type'],
                    ],
                    [
                        'location_name'   => $cp['location_name'],
                        'notes'           => $cp['notes'],
                        'barcode_scanned' => $delivery2->tracking_number,
                        'scanned_by_id'   => $logisticsUser?->id,
                        'hub_id'          => $cp['hub_id'],
                        'created_at'      => now()->subHours($cp['time_offset']),
                    ]
                );
            }
        }
    }
}
