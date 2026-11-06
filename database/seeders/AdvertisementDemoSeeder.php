<?php

namespace Database\Seeders;

use App\Models\Advertisement;
use App\Models\AdvertisementAction;
use App\Models\AdvertisementAsset;
use App\Models\AdvertisementPublication;
use App\Models\AdvertisementRevision;
use App\Models\Product;
use App\Models\User;
use App\Services\Advertisements\AdvertisementManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class AdvertisementDemoSeeder extends Seeder
{
    private const SLUG = 'bagoo-demo-homepage-banner';

    private const ASSET_TOKEN = '5a5bd365-193e-49ef-9b11-744621a99221';

    private const DRAFT_REQUEST = '177d52c1-2f7e-4e0e-9a64-300d08393f5d';

    private const PUBLISH_REQUEST = '2a586419-b8d1-4490-b1aa-d15ad44311a1';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $manager = app(AdvertisementManager::class);
        if (! $manager->installed()) {
            return;
        }

        $admin = User::query()->where('email', 'admin@bagoo.test')->where('role', 'admin')->first();
        $product = Product::query()->availableForSale()->where('status', 'active')->where('stock', '>', 0)->orderBy('products.id')->first();
        if (! $admin?->canAccessPortal() || ! $product) {
            return;
        }

        if ($existing = Advertisement::query()->where('slug', self::SLUG)->first()) {
            if ((int) $existing->created_by_id !== (int) $admin->id) {
                throw new RuntimeException('The reserved Bagoo demo banner slug belongs to another campaign. Existing data was preserved.');
            }

            // A rerun never republishes a paused, ended or otherwise edited campaign.
            return;
        }

        $source = public_path('images/buyer/advertisements/demo-preview.webp');
        if (! is_file($source) || ! ($image = getimagesize($source)) || $image[2] !== IMAGETYPE_WEBP
            || $image[0] !== 1880 || $image[1] !== 880) {
            throw new RuntimeException('The bundled Bagoo demo banner artwork is missing or has unexpected dimensions.');
        }
        $bytes = file_get_contents($source);
        if (! is_string($bytes) || $bytes === '') {
            throw new RuntimeException('The bundled Bagoo demo banner artwork could not be read.');
        }

        $paths = ['advertisements/bagoo-demo-homepage-banner/source.webp', 'advertisements/bagoo-demo-homepage-banner/image.webp'];
        $createdPaths = [];
        try {
            DB::transaction(function () use ($manager, $admin, $product, $bytes, $paths, &$createdPaths): void {
                $disk = Storage::disk('local');
                $checksum = hash('sha256', $bytes);
                foreach ($paths as $path) {
                    if ($disk->exists($path)) {
                        if (! hash_equals($checksum, hash('sha256', $disk->get($path)))) {
                            throw new RuntimeException('The reserved Bagoo demo banner file contains different data. Existing data was preserved.');
                        }

                        continue;
                    }
                    if (! $disk->put($path, $bytes)) {
                        throw new RuntimeException('The Bagoo demo banner artwork could not be stored.');
                    }
                    $createdPaths[] = $path;
                }

                $asset = AdvertisementAsset::create([
                    'token' => self::ASSET_TOKEN,
                    'uploaded_by_id' => $admin->id,
                    'checksum' => $checksum,
                    'source_path' => $paths[0],
                    'image_path' => $paths[1],
                    'mime' => 'image/webp',
                    'source_bytes' => strlen($bytes),
                    'image_bytes' => strlen($bytes),
                    'width' => 1880,
                    'height' => 880,
                ]);
                $ad = Advertisement::create(['slug' => self::SLUG, 'created_by_id' => $admin->id, 'version' => 0]);
                $beforeDraft = $manager->snapshot($ad);
                $startsAt = CarbonImmutable::now('UTC')->startOfSecond();
                $endsAt = $startsAt->addDays(30);
                $revision = AdvertisementRevision::create([
                    'advertisement_id' => $ad->id,
                    'revision_number' => 1,
                    'asset_id' => $asset->id,
                    'created_by_id' => $admin->id,
                    'purpose' => 'feature',
                    'title' => 'BagooPH Demo Collection',
                    'alt_text' => 'Bagoo demo collection banner for a sample product',
                    'summary' => 'Synthetic demo content for local review; not a paid seller placement.',
                    'destination_type' => 'product',
                    'destination_id' => $product->id,
                    'product_ids' => [],
                    'offer_revision_id' => null,
                    'slot' => 'main_1',
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'imported_candidate' => false,
                ]);
                $ad->update(['version' => 1]);
                $ad->refresh();
                $afterDraft = $manager->snapshot($ad);
                AdvertisementAction::create([
                    'advertisement_id' => $ad->id,
                    'actor_id' => $admin->id,
                    'actor_name' => 'Bagoo demo fixture',
                    'request_key' => self::DRAFT_REQUEST,
                    'fingerprint' => hash('sha256', 'bagoo-demo-homepage-banner:draft:v1'),
                    'source_version' => 0,
                    'action' => 'draft_saved',
                    'reason' => null,
                    'before_state' => $beforeDraft,
                    'after_state' => $afterDraft,
                ]);

                AdvertisementPublication::create([
                    'advertisement_id' => $ad->id,
                    'revision_id' => $revision->id,
                    'published_by_id' => $admin->id,
                    'slot' => 'main_1',
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'claims_reviewed' => true,
                ]);
                $ad->update(['version' => 2]);
                $ad->refresh();
                AdvertisementAction::create([
                    'advertisement_id' => $ad->id,
                    'actor_id' => $admin->id,
                    'actor_name' => 'Bagoo demo fixture',
                    'request_key' => self::PUBLISH_REQUEST,
                    'fingerprint' => hash('sha256', 'bagoo-demo-homepage-banner:publish:v1'),
                    'source_version' => 1,
                    'action' => 'publish',
                    'reason' => 'Synthetic local demo placement; no seller payment or order is represented.',
                    'before_state' => $afterDraft,
                    'after_state' => $manager->snapshot($ad),
                ]);
            }, 3);
        } catch (Throwable $exception) {
            foreach ($createdPaths as $path) {
                if (! AdvertisementAsset::query()->where('source_path', $path)->orWhere('image_path', $path)->exists()) {
                    Storage::disk('local')->delete($path);
                }
            }
            throw $exception;
        }
    }
}
