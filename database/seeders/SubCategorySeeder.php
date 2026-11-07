<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\SubCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SubCategorySeeder extends Seeder
{
    public function run(): void
    {
        /**
         * Exact 14 Master Categories and Subcategories from docs/CATEGORIES.md:
         * 1. Pet Supplies
         * 2. Electronics and Gadgets
         * 3. Women's Apparel
         * 4. Men's Apparel
         * 5. Kids and Baby
         * 6. Home and Garden
         * 7. Sports and Outdoors
         * 8. Health and Beauty
         * 9. Books and Media
         * 10. Food and Gourmet
         * 11. Automotive & Motorcycle (Group 9)
         * 12. Furniture and Office Equipment
         * 13. Jewelry and Watches
         * 14. Office and School Supplies (Group 12)
         */
        $taxonomy = [
            // 1. Pet Supplies
            'pet-supplies' => [
                'name' => 'Pet Supplies',
                'image' => '/images/categories/pet_supplies.jpg',
                'subs' => [
                    ['name' => 'Dog Food & Treats', 'slug' => 'dog-food-and-treats', 'sort_order' => 1],
                    ['name' => 'Cat Litter & Accessories', 'slug' => 'cat-litter-and-accessories', 'sort_order' => 2],
                    ['name' => 'Aquariums & Fish Supplies', 'slug' => 'aquariums-and-fish-supplies', 'sort_order' => 3],
                    ['name' => 'Bird Feeders & Food', 'slug' => 'bird-feeders-and-food', 'sort_order' => 4],
                    ['name' => 'Pet Grooming Products', 'slug' => 'pet-grooming-products', 'sort_order' => 5],
                    ['name' => 'Pet Health & Wellness', 'slug' => 'pet-health-and-wellness', 'sort_order' => 6],
                ],
            ],

            // 2. Electronics and Gadgets
            'electronics-and-gadgets' => [
                'name' => 'Electronics and Gadgets',
                'image' => '/images/categories/electronics_audio.jpg',
                'subs' => [
                    ['name' => 'Mobile Phones & Accessories', 'slug' => 'mobile-phones-and-accessories', 'sort_order' => 1],
                    ['name' => 'Laptops, Desktops & Monitors', 'slug' => 'laptops-desktops-and-monitors', 'sort_order' => 2],
                    ['name' => 'Audio & Video Equipment', 'slug' => 'audio-and-video-equipment', 'sort_order' => 3],
                    ['name' => 'Smart Home Devices', 'slug' => 'smart-home-devices', 'sort_order' => 4],
                    ['name' => 'Cameras & Photography', 'slug' => 'cameras-and-photography', 'sort_order' => 5],
                    ['name' => 'Wearable Technology', 'slug' => 'wearable-technology', 'sort_order' => 6],
                ],
            ],

            // 3. Women's Apparel
            'womens-apparel' => [
                'name' => "Women's Apparel",
                'image' => '/images/categories/womens_apparel.jpg',
                'subs' => [
                    ['name' => 'Dresses & Skirts', 'slug' => 'dresses-and-skirts', 'sort_order' => 1],
                    ['name' => 'Tops & Blouses', 'slug' => 'tops-and-blouses', 'sort_order' => 2],
                    ['name' => 'Activewear & Yoga Pants', 'slug' => 'activewear-and-yoga-pants', 'sort_order' => 3],
                    ['name' => 'Lingerie & Sleepwear', 'slug' => 'lingerie-and-sleepwear', 'sort_order' => 4],
                    ['name' => 'Jackets & Coats', 'slug' => 'jackets-and-coats', 'sort_order' => 5],
                    ['name' => 'Shoes & Accessories', 'slug' => 'womens-shoes-and-accessories', 'sort_order' => 6],
                ],
            ],

            // 4. Men's Apparel
            'mens-apparel' => [
                'name' => "Men's Apparel",
                'image' => '/images/products/boxy_tee_front.jpg',
                'subs' => [
                    ['name' => 'Suits & Blazers', 'slug' => 'suits-and-blazers', 'sort_order' => 1],
                    ['name' => 'Casual Shirts & Pants', 'slug' => 'casual-shirts-and-pants', 'sort_order' => 2],
                    ['name' => 'Outerwear & Jackets', 'slug' => 'outerwear-and-jackets', 'sort_order' => 3],
                    ['name' => 'Activewear & Fitness Gear', 'slug' => 'mens-activewear-and-fitness-gear', 'sort_order' => 4],
                    ['name' => 'Shoes & Accessories', 'slug' => 'mens-shoes-and-accessories', 'sort_order' => 5],
                    ['name' => 'Grooming Products', 'slug' => 'mens-grooming-products', 'sort_order' => 6],
                    ['name' => 'Tees & Tops', 'slug' => 'tees-and-tops', 'sort_order' => 7],
                ],
            ],

            // 5. Kids and Baby
            'kids-and-baby' => [
                'name' => 'Kids and Baby',
                'image' => '/images/categories/kids_baby.jpg',
                'subs' => [
                    ['name' => 'Baby Clothes & Accessories', 'slug' => 'baby-clothes-and-accessories', 'sort_order' => 1],
                    ['name' => 'Toys & Games', 'slug' => 'toys-and-games', 'sort_order' => 2],
                    ['name' => 'Educational Materials', 'slug' => 'educational-materials', 'sort_order' => 3],
                    ['name' => 'Strollers & Gear', 'slug' => 'strollers-and-gear', 'sort_order' => 4],
                    ['name' => 'Nursery Furniture', 'slug' => 'nursery-furniture', 'sort_order' => 5],
                    ['name' => 'Safety and Health', 'slug' => 'safety-and-health', 'sort_order' => 6],
                ],
            ],

            // 6. Home and Garden
            'home-and-garden' => [
                'name' => 'Home and Garden',
                'image' => '/images/carousel/botanical-candle.jpg',
                'subs' => [
                    ['name' => 'Kitchen Appliances', 'slug' => 'kitchen-appliances', 'sort_order' => 1],
                    ['name' => 'Furniture & Decor', 'slug' => 'furniture-and-decor', 'sort_order' => 2],
                    ['name' => 'Gardening Tools', 'slug' => 'gardening-tools', 'sort_order' => 3],
                    ['name' => 'Outdoor Living', 'slug' => 'outdoor-living', 'sort_order' => 4],
                    ['name' => 'Home Improvement Tools', 'slug' => 'home-improvement-tools', 'sort_order' => 5],
                    ['name' => 'Bedding & Bath', 'slug' => 'bedding-and-bath', 'sort_order' => 6],
                ],
            ],

            // 7. Sports and Outdoors
            'sports-and-outdoors' => [
                'name' => 'Sports and Outdoors',
                'image' => '/images/categories/sports_outdoors.jpg',
                'subs' => [
                    ['name' => 'Fitness Equipment', 'slug' => 'fitness-equipment', 'sort_order' => 1],
                    ['name' => 'Camping & Hiking Gear', 'slug' => 'camping-and-hiking-gear', 'sort_order' => 2],
                    ['name' => 'Sports Apparel', 'slug' => 'sports-apparel', 'sort_order' => 3],
                    ['name' => 'Cycling & Bikes', 'slug' => 'cycling-and-bikes', 'sort_order' => 4],
                    ['name' => 'Water Sports', 'slug' => 'water-sports', 'sort_order' => 5],
                    ['name' => 'Team Sports Equipment', 'slug' => 'team-sports-equipment', 'sort_order' => 6],
                ],
            ],

            // 8. Health and Beauty
            'health-and-beauty' => [
                'name' => 'Health and Beauty',
                'image' => '/images/categories/health_beauty.jpg',
                'subs' => [
                    ['name' => 'Skincare Products', 'slug' => 'skincare-products', 'sort_order' => 1],
                    ['name' => 'Haircare Solutions', 'slug' => 'haircare-solutions', 'sort_order' => 2],
                    ['name' => 'Makeup & Cosmetics', 'slug' => 'makeup-and-cosmetics', 'sort_order' => 3],
                    ['name' => 'Personal Care Appliances', 'slug' => 'personal-care-appliances', 'sort_order' => 4],
                    ['name' => "Men's Grooming", 'slug' => 'grooming-products', 'sort_order' => 5],
                    ['name' => 'Health Supplements', 'slug' => 'health-supplements', 'sort_order' => 6],
                ],
            ],

            // 9. Books and Media
            'books-and-media' => [
                'name' => 'Books and Media',
                'image' => '/images/categories/books_media.jpg',
                'subs' => [
                    ['name' => 'Fiction & Non-Fiction Books', 'slug' => 'fiction-and-nonfiction-books', 'sort_order' => 1],
                    ['name' => 'Magazines & Periodicals', 'slug' => 'magazines-and-periodicals', 'sort_order' => 2],
                    ['name' => 'Music CDs & Vinyl Records', 'slug' => 'music-cds-and-vinyl-records', 'sort_order' => 3],
                    ['name' => 'Movie DVDs & Blu-ray', 'slug' => 'movie-dvds-and-bluray', 'sort_order' => 4],
                    ['name' => 'Video Games & Consoles', 'slug' => 'video-games-and-consoles', 'sort_order' => 5],
                    ['name' => 'Educational DVDs', 'slug' => 'educational-dvds', 'sort_order' => 6],
                ],
            ],

            // 10. Food and Gourmet
            'food-and-gourmet' => [
                'name' => 'Food and Gourmet',
                'image' => '/images/categories/food_coffee.jpg',
                'subs' => [
                    ['name' => 'Baking Supplies & Ingredients', 'slug' => 'baking-supplies-and-ingredients', 'sort_order' => 1],
                    ['name' => 'Coffee, Tea & Beverages', 'slug' => 'coffee-tea-and-beverages', 'sort_order' => 2],
                    ['name' => 'Snacks & Candy', 'slug' => 'snacks-and-candy', 'sort_order' => 3],
                    ['name' => 'Specialty Foods & International Cuisine', 'slug' => 'specialty-foods-and-international-cuisine', 'sort_order' => 4],
                    ['name' => 'Organic and Health Foods', 'slug' => 'organic-and-health-foods', 'sort_order' => 5],
                    ['name' => 'Meal Kits & Prepped Foods', 'slug' => 'meal-kits-and-prepped-foods', 'sort_order' => 6],
                ],
            ],

            // 11. Automotive & Motorcycle (Group 9)
            'automotive-motorcycle-group-9' => [
                'name' => 'Automotive & Motorcycle (Group 9)',
                'image' => '/images/categories/automotive.jpg',
                'subs' => [
                    ['name' => 'Protective Gear', 'slug' => 'protective-gear', 'sort_order' => 1],
                    ['name' => 'Maintenance & Repair Tools', 'slug' => 'maintenance-and-repair-tools', 'sort_order' => 2],
                    ['name' => 'Parts & Accessories', 'slug' => 'parts-and-accessories', 'sort_order' => 3],
                    ['name' => 'Electrical Components', 'slug' => 'electrical-components', 'sort_order' => 4],
                    ['name' => 'Tires, Wheels, and Fluids', 'slug' => 'tires-wheels-and-fluids', 'sort_order' => 5],
                ],
            ],

            // 12. Furniture and Office Equipment
            'furniture-and-office-equipment' => [
                'name' => 'Furniture and Office Equipment',
                'image' => '/images/categories/furniture_decor.jpg',
                'subs' => [
                    ['name' => 'Office Desks & Chairs', 'slug' => 'office-desks-and-chairs', 'sort_order' => 1],
                    ['name' => 'Storage Cabinets & Shelving', 'slug' => 'storage-cabinets-and-shelving', 'sort_order' => 2],
                    ['name' => 'Conference & Meeting Furniture', 'slug' => 'conference-and-meeting-furniture', 'sort_order' => 3],
                    ['name' => 'Computer Tables & Workstations', 'slug' => 'computer-tables-and-workstations', 'sort_order' => 4],
                    ['name' => 'Ergonomic Accessories', 'slug' => 'ergonomic-accessories', 'sort_order' => 5],
                    ['name' => 'Office Lighting & Fixtures', 'slug' => 'office-lighting-and-fixtures', 'sort_order' => 6],
                ],
            ],

            // 13. Jewelry and Watches
            'jewelry-and-watches' => [
                'name' => 'Jewelry and Watches',
                'image' => '/images/categories/jewelry_watches.jpg',
                'subs' => [
                    ['name' => 'Necklaces & Pendants', 'slug' => 'necklaces-and-pendants', 'sort_order' => 1],
                    ['name' => 'Rings & Earrings', 'slug' => 'rings-and-earrings', 'sort_order' => 2],
                    ['name' => 'Bracelets & Bangles', 'slug' => 'bracelets-and-bangles', 'sort_order' => 3],
                    ['name' => 'Watches for Men & Women', 'slug' => 'watches-for-men-and-women', 'sort_order' => 4],
                    ['name' => 'Fashion Jewelry', 'slug' => 'fashion-jewelry', 'sort_order' => 5],
                    ['name' => 'Jewelry Storage & Care', 'slug' => 'jewelry-storage-and-care', 'sort_order' => 6],
                    ['name' => 'Tactical & Field Watches', 'slug' => 'tactical-field-watches', 'sort_order' => 7],
                ],
            ],

            // 14. Office and School Supplies (Group 12)
            'office-and-school-supplies-group-12' => [
                'name' => 'Office and School Supplies (Group 12)',
                'image' => '/images/categories/office_school.jpg',
                'subs' => [
                    ['name' => 'Notebooks & Paper Products', 'slug' => 'notebooks-and-paper-products', 'sort_order' => 1],
                    ['name' => 'Writing Instruments', 'slug' => 'writing-instruments', 'sort_order' => 2],
                    ['name' => 'Office Furniture', 'slug' => 'office-furniture', 'sort_order' => 3],
                    ['name' => 'Printers & Printing Supplies', 'slug' => 'printers-and-printing-supplies', 'sort_order' => 4],
                    ['name' => 'School Bags & Backpacks', 'slug' => 'school-bags-and-backpacks', 'sort_order' => 5],
                    ['name' => 'Arts & Craft Materials', 'slug' => 'arts-and-craft-materials', 'sort_order' => 6],
                ],
            ],

            // Legacy Bag & EDC Category (ID 1 in seed)
            'backpacks-and-bags' => [
                'name' => 'Men\'s Gear & Everyday Carry',
                'image' => '/images/products/stealth_sling_front.jpg',
                'subs' => [
                    ['name' => 'Crossbody Slings & Chest Packs', 'slug' => 'crossbody-slings', 'sort_order' => 1],
                    ['name' => 'Commuter Backpacks & Roll-Tops', 'slug' => 'commuter-backpacks', 'sort_order' => 2],
                    ['name' => 'Modular Tech Pouches & Cable Organizers', 'slug' => 'tech-pouches-and-organizers', 'sort_order' => 3],
                    ['name' => 'Wallets & Leathercraft', 'slug' => 'wallets-and-leathercraft', 'sort_order' => 4],
                    ['name' => 'EDC Hardware', 'slug' => 'edc-hardware', 'sort_order' => 5],
                    ['name' => 'Travel & Duffel Bags', 'slug' => 'travel-and-duffels', 'sort_order' => 6],
                ],
            ],
        ];

        foreach ($taxonomy as $catSlug => $dept) {
            $category = Category::where('slug', $catSlug)->first();

            if (! $category) {
                $category = Category::create([
                    'name' => $dept['name'],
                    'slug' => $catSlug,
                    'image' => $dept['image'] ?? null,
                    'is_active' => true,
                ]);
            } else {
                $updateData = [];
                if (! empty($dept['image']) && empty($category->image)) {
                    $updateData['image'] = $dept['image'];
                }
                if (! empty($updateData)) {
                    $category->update($updateData);
                }
            }

            foreach ($dept['subs'] as $subItem) {
                SubCategory::updateOrCreate(
                    [
                        'category_id' => $category->id,
                        'slug' => $subItem['slug'],
                    ],
                    [
                        'name' => $subItem['name'],
                        'sort_order' => $subItem['sort_order'],
                        'is_active' => true,
                    ]
                );
            }
        }

        // Map seeded products to their relevant subcategories
        $productSubcategoryMap = [
            'Seamless Breathable Cotton Briefs (3-Pack)' => 'casual-shirts-and-pants',
            'Vanguard Commuter Backpack 26L' => 'commuter-backpacks',
            'Nomad Modular Crossbody Tech Pouch' => 'tech-pouches-and-organizers',
            'Aero Graphite Weatherproof Roll-Top 26L' => 'commuter-backpacks',
            'Stealth Urban Crossbody Sling 8L' => 'crossbody-slings',
            'Horizon Expandable Travel Pack 35L' => 'travel-and-duffels',
            'Kinetic Modular Tactical Chest Pack' => 'crossbody-slings',
            'Transit Executive Leather-Trim Briefpack 20L' => 'commuter-backpacks',
            'Tactical Quick-Release Cobra Belt' => 'mens-shoes-and-accessories',
            'Minimalist Horween Leather Bifold Wallet' => 'wallets-and-leathercraft',
            'Matte Black Titanium Field Watch 42mm' => 'watches-for-men-and-women',
            'Polarized Aviator Sunglasses Matte Gunmetal' => 'mens-shoes-and-accessories',
            'Structured 5-Panel Water-Repellent Camp Cap' => 'mens-shoes-and-accessories',
            'Heavy-Duty Anodized EDC Carabiner Clip' => 'edc-hardware',
            'Mil-Spec 550 Paracord Survival Bracelet' => 'bracelets-and-bangles',
            'Touchscreen Tactical Utility Gloves' => 'protective-gear',
            'Heavyweight Boxy Cotton Oversized Tee' => 'tees-and-tops',
            'Heritage Full-Grain Leather Watch Strap 22mm' => 'watches-for-men-and-women',
            'AeroPro Wireless Active Noise-Cancelling Headphones' => 'audio-and-video-equipment',
            'ApexForge RGB Hot-Swappable Mechanical Keyboard' => 'laptops-desktops-and-monitors',
            'PulseSpeed Ultra-Light Wireless Gaming Mouse' => 'mobile-phones-and-accessories',
            'ThermaShield 32oz Insulated Stainless Steel Flask' => 'camping-and-hiking-gear',
            'Lumina Desk Architect LED Eye-Care Task Lamp' => 'office-lighting-and-fixtures',
            'BaristaCraft Stainless Steel French Coffee Press 800ml' => 'kitchen-appliances',
        ];

        foreach ($productSubcategoryMap as $productName => $subSlug) {
            $sub = SubCategory::where('slug', $subSlug)->first();
            if ($sub) {
                Product::where('name', $productName)->update([
                    'sub_category_id' => $sub->id,
                ]);
            }
        }
    }
}
