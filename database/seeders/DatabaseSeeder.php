<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Seed Single Platform Admin Account (Andy Super Admin)
        User::updateOrCreate(
            ['email' => 'sarneandy6@gmail.com'],
            [
                'name' => 'Andy Super Admin',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'phone' => '+63 917 888 8888',
                'address' => '100 Bagoo HQ Way, Floor 12',
                'city' => 'Taguig, Metro Manila',
                'postal_code' => '1634',
                'status' => 'active',
                'kyc_status' => 'approved',
                'kyc_reviewed_at' => now(),
            ]
        );


        // 3. Create 14 Verified Departments
        $categoriesData = [
            [
                'name' => 'Backpacks & Bags',
                'slug' => 'backpacks-and-bags',
                'icon' => 'ShoppingBag',
                'image' => 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=600&q=80',
                'description' => 'Ergonomic, waterproof, and modular packs for commute and travel.',
            ],
            [
                'name' => 'Consumer Electronics',
                'slug' => 'consumer-electronics',
                'icon' => 'Laptop',
                'image' => 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?auto=format&fit=crop&w=600&q=80',
                'description' => 'Smart gadgets, ANC audio, mechanical peripherals, and gear.',
            ],
            [
                'name' => 'Apparel & Techwear',
                'slug' => 'apparel-and-footwear',
                'icon' => 'Shirt',
                'image' => 'https://images.unsplash.com/photo-1523381210434-271e8be1f52b?auto=format&fit=crop&w=600&q=80',
                'description' => 'Heavyweight organic cotton, waterproof shells, and streetwear.',
            ],
            [
                'name' => 'Travel & Luggage',
                'slug' => 'travel-and-accessories',
                'icon' => 'Compass',
                'image' => 'https://images.unsplash.com/photo-1581553680321-4fffae59fccd?auto=format&fit=crop&w=600&q=80',
                'description' => 'Polycarbonate spinners, packing cubes, and RFID organizers.',
            ],
            [
                'name' => 'Footwear & Sneakers',
                'slug' => 'footwear-and-sneakers',
                'icon' => 'Footprints',
                'image' => 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=600&q=80',
                'description' => 'Cushioned running shoes, trail sneakers, and slip-ons.',
            ],
            [
                'name' => 'Smart Home & Living',
                'slug' => 'smart-home-and-living',
                'icon' => 'Home',
                'image' => 'https://images.unsplash.com/photo-1583847268964-b28dc8f51f92?auto=format&fit=crop&w=600&q=80',
                'description' => 'Minimalist desk lamps, ambient LED bars, and acoustic diffusers.',
            ],
            [
                'name' => 'Beauty & Grooming',
                'slug' => 'beauty-and-grooming',
                'icon' => 'Sparkles',
                'image' => 'https://images.unsplash.com/photo-1522337360788-8b13dee7a37e?auto=format&fit=crop&w=600&q=80',
                'description' => 'Organic botanical skincare, beard balms, and facial cleansers.',
            ],
            [
                'name' => 'Mobile & Gadgets',
                'slug' => 'mobile-and-gadgets',
                'icon' => 'Smartphone',
                'image' => 'https://images.unsplash.com/photo-1580910051074-3eb694886505?auto=format&fit=crop&w=600&q=80',
                'description' => 'MagSafe power banks, titanium cables, and precision cases.',
            ],
            [
                'name' => 'Audio & Spatial Sound',
                'slug' => 'audio-and-spatial-sound',
                'icon' => 'Headphones',
                'image' => 'https://images.unsplash.com/photo-1546435770-a3e426bf472b?auto=format&fit=crop&w=600&q=80',
                'description' => 'Hi-Res DAC amplifiers, true wireless earbuds, and studio monitors.',
            ],
            [
                'name' => 'Sports & Outdoors',
                'slug' => 'sports-and-outdoor',
                'icon' => 'Activity',
                'image' => 'https://images.unsplash.com/photo-1517838277536-f5f99be501cd?auto=format&fit=crop&w=600&q=80',
                'description' => 'Insulated flasks, compression sleeves, and hydration packs.',
            ],
            [
                'name' => 'Watches & EDC',
                'slug' => 'watches-and-edc',
                'icon' => 'Watch',
                'image' => 'https://images.unsplash.com/photo-1524805444758-089113d48a6d?auto=format&fit=crop&w=600&q=80',
                'description' => 'Automatic field watches, titanium pens, and pocket tools.',
            ],
            [
                'name' => 'Automotive & Moto',
                'slug' => 'automotive-essentials',
                'icon' => 'Car',
                'image' => 'https://images.unsplash.com/photo-1563720223185-11003d516935?auto=format&fit=crop&w=600&q=80',
                'description' => 'Phone car mounts, tire inflators, and detailing microfibers.',
            ],
            [
                'name' => 'Desk Studio & Stationery',
                'slug' => 'desk-studio-and-stationery',
                'icon' => 'PenTool',
                'image' => 'https://images.unsplash.com/photo-1585776245991-cf89dd7fc73a?auto=format&fit=crop&w=600&q=80',
                'description' => 'Full-grain leather desk mats, brass rulers, and grid notebooks.',
            ],
            [
                'name' => 'Health & Wellness',
                'slug' => 'health-and-wellness',
                'icon' => 'HeartPulse',
                'image' => 'https://images.unsplash.com/photo-1544367567-0f2fcb009e0b?auto=format&fit=crop&w=600&q=80',
                'description' => 'Massage guns, posture correctors, and ergonomic seat cushions.',
            ],
        ];

        $categories = [];
        foreach ($categoriesData as $c) {
            $categories[$c['slug']] = Category::updateOrCreate(['slug' => $c['slug']], $c);
        }

        // 3. Seed Platform Vouchers
        \App\Models\Voucher::updateOrCreate(
            ['code' => 'PAYDAY70'],
            [
                'name' => 'Payday 10% Discount',
                'description' => '10% discount on all orders above ₱500',
                'discount_type' => 'percent',
                'discount_value' => 10.00,
                'min_spend' => 500.00,
                'max_discount' => 500.00,
                'is_active' => true,
            ]
        );

        \App\Models\Voucher::updateOrCreate(
            ['code' => 'FREESHIP'],
            [
                'name' => 'Free Delivery Voucher',
                'description' => 'Zero shipping fee with no minimum spend required',
                'discount_type' => 'free_shipping',
                'discount_value' => 50.00,
                'min_spend' => 0.00,
                'is_active' => true,
            ]
        );

        \App\Models\Voucher::updateOrCreate(
            ['code' => 'BAGOO10'],
            [
                'name' => 'New Buyer ₱200 Bonus',
                'description' => '₱200 flat discount on orders ₱1,000 and up',
                'discount_type' => 'fixed',
                'discount_value' => 200.00,
                'min_spend' => 1000.00,
                'is_active' => true,
            ]
        );
    }
}
