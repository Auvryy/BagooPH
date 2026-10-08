<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\IdentityCorrectionRequest;
use App\Models\KycDecision;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Shop;
use App\Models\ShopReviewDecision;
use App\Models\User;
use App\Services\MasterCategoryService;
use App\Services\ShopEligibilityService;
use App\Services\ShopReviewService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class SellerDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(MasterCategorySeeder::class);
        $createdFiles = [];
        try {
            DB::transaction(function () use (&$createdFiles) {
                $seller = User::firstOrCreate(['email' => 'seller@bagoo.test'], [
                    'name' => 'Los Banos Seller', 'password' => 'Password1234', 'email_verified_at' => now(),
                    'role' => 'seller', 'phone' => '+639170000003', 'address' => 'Lopez Avenue, Batong Malake',
                    'city' => 'Los Banos, Laguna', 'postal_code' => '4030', 'status' => 'active',
                    'kyc_status' => 'approved', 'kyc_reviewed_at' => now(),
                ]);
                $seller = User::whereKey($seller->id)->lockForUpdate()->firstOrFail();
                if (! $seller->isSeller() || ! $seller->canAccessPortal()) {
                    throw new RuntimeException('The reserved demo seller has a conflicting role or account restriction. Existing data was preserved.');
                }
                $shop = Shop::firstOrCreate(['user_id' => $seller->id], [
                    'name' => 'Apex Gear & Studio', 'slug' => 'apex-gear-and-studio',
                    'description' => 'Commuter bags and everyday accessories for the Bagoo demo.',
                    'phone' => $seller->phone, 'address' => $seller->address, 'city' => $seller->city,
                    'status' => 'active', 'rating' => 0,
                ]);
                $shop = Shop::whereKey($shop->id)->lockForUpdate()->firstOrFail();
                if ($shop->review_status === null && $shop->review_decision_id === null
                    && ! $shop->reviewDecisions()->exists() && $shop->slug === 'apex-gear-and-studio'
                    && $shop->status === 'active' && $seller->identity_version === 0
                    && ! KycDecision::where('user_id', $seller->id)->exists()
                    && ! IdentityCorrectionRequest::where('user_id', $seller->id)->exists()) {
                    $this->prepareDemoReview($seller, $shop, $createdFiles);
                }
                $eligibility = app(ShopEligibilityService::class);
                if (! $eligibility->isEligible($shop->fresh())) {
                    throw new RuntimeException('The demo shop needs its existing approval or restriction resolved. The seeder does not replace real review history.');
                }
                $category = Category::firstOrCreate([
                    'slug' => 'bagoo-demo-accessories-'.$shop->root_category_id,
                ], [
                    'name' => 'Bags & Everyday Accessories', 'parent_id' => $shop->root_category_id,
                    'icon' => 'ShoppingBag', 'is_active' => true,
                    'image' => '/images/products/vanguard_commuter_front.jpg',
                ]);
                if (! in_array($category->id, $eligibility->categoryIds($shop), true)) {
                    throw new RuntimeException('The demo accessory category has a conflicting scope or restriction. Existing categories were preserved.');
                }
                foreach ($this->catalogue() as $data) {
                    $images = $data['images'];
                    unset($data['images'], $data['category']);
                    $product = Product::firstOrCreate(['shop_id' => $shop->id, 'sku' => $data['sku']], $data + [
                        'category_id' => $category->id, 'featured_image' => $images[0]['url'],
                        'status' => 'active', 'rating' => 0, 'sales_count' => 0,
                    ]);
                    // Repair only known fixture products in the old uncategorized demo collection.
                    if (! $product->wasRecentlyCreated && $product->category?->is_active && $product->category->slug === 'backpacks-and-bags'
                        && $product->category->parent_id === null) {
                        $product->update(['category_id' => $category->id]);
                    }
                    if (! $product->images()->exists()) {
                        foreach ($images as $index => $image) {
                            ProductImage::create(['product_id' => $product->id, 'image_url' => $image['url'],
                                'is_primary' => $image['is_primary'], 'sort_order' => $index]);
                        }
                    }
                }
            }, 3);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($createdFiles);
            throw $exception;
        }
    }

    private function prepareDemoReview(User $seller, Shop $shop, array &$createdFiles): void
    {
        $before = $shop->only(['status', 'review_status', 'reviewed_at', 'review_feedback']);
        $rootId = $shop->root_category_id ?? app(MasterCategoryService::class)->activeRoots()->where('name', "Men's Apparel")->firstOrFail()->id;
        app(MasterCategoryService::class)->requireEligible($rootId);
        $seller->update([
            'birthday' => $seller->birthday?->toDateString() ?? '1995-05-10',
            'id_document_path' => $seller->getRawOriginal('id_document_path') ?? $this->demoDocument($seller, 'id', $createdFiles),
            'business_permit_path' => $seller->getRawOriginal('business_permit_path') ?? $this->demoDocument($seller, 'permit', $createdFiles),
        ]);
        $shop->update(['root_category_id' => $rootId,
            'business_permit_path' => $shop->business_permit_path ?? $seller->getRawOriginal('business_permit_path'),
            'review_submitted_at' => now(), 'review_version' => $shop->review_version + 1]);
        $review = app(ShopReviewService::class);
        $submission = $review->submission($shop->fresh());
        if ($issues = $review->issues($shop, $submission)) {
            throw new RuntimeException('The demo fixture is incomplete: '.implode(' ', $issues));
        }
        $admin = User::firstOrCreate(['email' => 'admin@bagoo.test'], [
            'name' => 'Bagoo Admin', 'role' => 'admin', 'password' => 'Password1234',
            'email_verified_at' => now(), 'status' => 'active', 'kyc_status' => 'approved', 'kyc_reviewed_at' => now(),
        ]);
        if (! $admin->isAdmin() || ! $admin->canAccessPortal()) {
            throw new RuntimeException('The reserved demo admin is unavailable. Existing account roles and restrictions were preserved.');
        }
        // This explicit fixture is not evidence of a human document review or a historical approval.
        $submission['source'] = 'demo_fixture';
        $reason = 'Synthetic demo fixture only; not a manual Platform Admin document review.';
        $shop->update(['review_status' => 'approved', 'reviewed_at' => now(), 'review_feedback' => $reason]);
        $decision = ShopReviewDecision::create([
            'shop_id' => $shop->id, 'seller_id' => $seller->id, 'reviewer_id' => $admin->id,
            'reviewer_role' => 'admin', 'reviewer_name' => 'Bagoo demo setup', 'root_category_id' => $rootId,
            'submission_token' => $review->token($submission), 'decision' => 'approved', 'reason' => $reason,
            'submission' => $submission, 'before_state' => $before,
            'after_state' => $shop->only(['status', 'review_status', 'reviewed_at', 'review_feedback']), 'reviewed_at' => $shop->reviewed_at,
        ]);
        $shop->update(['review_decision_id' => $decision->id]);
    }

    private function demoDocument(User $seller, string $kind, array &$createdFiles): string
    {
        // Private image placeholders belong only to this explicitly labelled fictional fixture.
        $path = 'kyc_documents/bagoo-demo-seller-'.$seller->id.'-'.$kind.'.png';
        if (! Storage::disk('local')->exists($path)) {
            $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aM1sAAAAASUVORK5CYII=', true);
            if (! Storage::disk('local')->put($path, $image, ['visibility' => 'private'])) {
                throw new RuntimeException('The demo fixture document could not be stored.');
            }
            $createdFiles[] = $path;
        }

        return $path;
    }

    private function catalogue(): array
    {
        $productsData = [
            [
                'name' => 'Vanguard Commuter Backpack 26L',
                'slug' => 'vanguard-commuter-backpack-26l',
                'description' => 'Engineered for high-density daily transit. Constructed from weatherproof 500D ballistic nylon with magnetic sternum clips, a quick-access clamshell main compartment, and a dedicated suspended 16-inch padded laptop sleeve with waterproof zippers.',
                'price' => 2850.00,
                'compare_at_price' => 3500.00,
                'stock' => 20,
                'sku' => 'APX-VNG-001',
                'weight_kg' => 1.10,
                'images' => [
                    ['url' => '/images/products/vanguard_commuter_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/vanguard_commuter_angle.jpg', 'is_primary' => false],
                ],
            ],
            [
                'name' => 'Nomad Modular Crossbody Tech Pouch',
                'slug' => 'nomad-modular-crossbody-tech-pouch',
                'description' => 'Versatile modular tech organization pouch with detachable paracord shoulder strap. Multi-tier elastic loops hold power banks, cables, SSDs, and stylus pens. Weather-resistant ripstop canvas shell with high-visibility interior organizing mesh.',
                'price' => 1150.00,
                'compare_at_price' => 1450.00,
                'stock' => 20,
                'sku' => 'APX-NMD-004',
                'weight_kg' => 0.35,
                'images' => [
                    ['url' => '/images/products/nomad_pouch_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/nomad_pouch_open.jpg', 'is_primary' => false],
                ],
            ],
            [
                'name' => 'Aero Graphite Weatherproof Roll-Top 26L',
                'slug' => 'aero-graphite-weatherproof-roll-top-26l',
                'description' => 'Heavy-duty roll-top backpack engineered for intense tropical monsoons and cycle commutes. Features high-frequency welded seams, reinforced anodized aluminum hardware, exterior U-lock holster, and contoured ergonomic shoulder straps.',
                'price' => 2750.00,
                'compare_at_price' => 3350.00,
                'stock' => 20,
                'sku' => 'APX-GRP-011',
                'weight_kg' => 1.05,
                'images' => [
                    ['url' => '/images/products/aero_rolltop_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/aero_rolltop_angle.jpg', 'is_primary' => false],
                ],
            ],
            [
                'name' => 'Stealth Urban Crossbody Sling 8L',
                'slug' => 'stealth-urban-crossbody-sling-8l',
                'description' => 'Low-profile crossbody sling bag engineered for streamlined urban mobility. Features weather-sealed zippers, internal RFID-shielded passport pocket, quick-release aluminum shoulder buckle, and breathable air-mesh back padding.',
                'price' => 1450.00,
                'compare_at_price' => 1850.00,
                'stock' => 20,
                'sku' => 'APX-STL-002',
                'weight_kg' => 0.45,
                'images' => [
                    ['url' => '/images/products/stealth_sling_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/stealth_sling_angle.jpg', 'is_primary' => false],
                ],
            ],
            [
                'name' => 'Horizon Expandable Travel Pack 35L',
                'slug' => 'horizon-expandable-travel-pack-35l',
                'description' => 'Airline carry-on approved expandable weekender travel pack. Expands from 28L daily mode to 35L flight mode. Features full 180-degree suitcase opening, integrated compression straps, dirty laundry separation pod, and hidden passport security pocket.',
                'price' => 3950.00,
                'compare_at_price' => 4800.00,
                'stock' => 20,
                'sku' => 'APX-HRZ-003',
                'weight_kg' => 1.45,
                'images' => [
                    ['url' => '/images/products/horizon_travel_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/horizon_travel_side.jpg', 'is_primary' => false],
                ],
            ],
            [
                'name' => 'Kinetic Modular Tactical Chest Pack',
                'slug' => 'kinetic-modular-tactical-chest-pack',
                'description' => 'Ergonomic hands-free chest utility rig with 4-point quick-adjust harness. Dual zippered main chambers accommodate EDC tools, smartphone, and wallet. Reinforced laser-cut MOLLE attachment points on front face for modular expansions.',
                'price' => 1350.00,
                'compare_at_price' => 1750.00,
                'stock' => 20,
                'sku' => 'APX-KNT-006',
                'weight_kg' => 0.50,
                'images' => [
                    ['url' => '/images/products/kinetic_chest_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/kinetic_chest_angle.jpg', 'is_primary' => false],
                ],
            ],
            [
                'name' => 'Transit Executive Leather-Trim Briefpack 20L',
                'slug' => 'transit-executive-leather-trim-briefpack-20l',
                'description' => 'Sophisticated business commuter backpack seamlessly bridging the boardroom and the metro. Crafted from ballistic twill nylon accented with full-grain vegetable-tanned leather handles and zipper pulls. Structured standing base with luggage trolley pass-through.',
                'price' => 3650.00,
                'compare_at_price' => 4500.00,
                'stock' => 20,
                'sku' => 'APX-TRN-007',
                'weight_kg' => 1.25,
                'images' => [
                    ['url' => '/images/products/transit_brief_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/transit_brief_detail.jpg', 'is_primary' => false],
                ],
            ],
        ];

        // Include the existing accessory fixtures only when their public assets exist.
        $promptsFile = __DIR__.'/nano_banana_prompts.json';
        if (file_exists($promptsFile)) {
            $extraData = json_decode(file_get_contents($promptsFile), true)['products'] ?? [];
            foreach ($extraData as $extra) {
                $primaryImgPath = public_path($extra['images'][0]['url']);
                if (file_exists($primaryImgPath)) {
                    $productsData[] = $extra;
                }
            }
        }

        return $productsData;
    }
}
