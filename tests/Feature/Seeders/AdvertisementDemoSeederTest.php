<?php

namespace Tests\Feature\Seeders;

use App\Models\Advertisement;
use App\Models\AdvertisementAction;
use App\Models\AdvertisementPublication;
use App\Services\Advertisements\AdvertisementReader;
use Database\Seeders\AdvertisementDemoSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdvertisementDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private string $privateStorageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->privateStorageRoot = sys_get_temp_dir().'/bagoo-advertisement-demo-'.Str::uuid();
        File::ensureDirectoryExists($this->privateStorageRoot);
        Storage::set('local', app('filesystem')->build(['driver' => 'local', 'root' => $this->privateStorageRoot, 'throw' => true]));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        File::deleteDirectory($this->privateStorageRoot);
    }

    public function test_database_demo_seed_shows_one_synthetic_banner_and_reruns_preserve_its_state(): void
    {
        $this->seed(DatabaseSeeder::class);

        $advertisement = Advertisement::where('slug', 'bagoo-demo-homepage-banner')->sole();
        $this->assertSame('BagooPH Demo Collection', $advertisement->latestRevision->title);
        $this->assertSame('Synthetic demo content for local review; not a paid seller placement.', $advertisement->latestRevision->summary);
        $mine = fn () => collect(app(AdvertisementReader::class)->homepage(false))->filter(fn ($banner) => str_contains($banner['href'], 'bagoo-demo-homepage-banner'));
        $this->assertCount(1, $mine());
        $this->assertSame(2, AdvertisementAction::where('advertisement_id', $advertisement->id)->count());
        $this->assertDatabaseCount('orders', 0);
        $this->assertTrue(Storage::disk('local')->exists('advertisements/bagoo-demo-homepage-banner/image.webp'));

        $publication = AdvertisementPublication::where('advertisement_id', $advertisement->id)->sole();
        $ads = Advertisement::count();
        $this->seed(AdvertisementDemoSeeder::class);
        $this->assertSame($ads, Advertisement::count());
        $this->assertSame(2, AdvertisementAction::where('advertisement_id', $advertisement->id)->count());
        $this->assertCount(1, $mine());

        $publication->update(['ended_at' => now()]);
        $this->seed(AdvertisementDemoSeeder::class);
        $this->assertNotNull($publication->fresh()->ended_at);
        $this->assertCount(0, $mine());
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_fresh_database_replaces_banner_files_left_by_an_earlier_seed(): void
    {
        // ./bagoo.sh fresh wipes the database but keeps stored files; the seed must not stop on them.
        Storage::disk('local')->put('advertisements/bagoo-demo-homepage-banner/image.webp', 'old artwork from a wiped database');
        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(Advertisement::where('slug', 'bagoo-demo-homepage-banner')->exists());
        $this->assertSame(hash_file('sha256', public_path('images/buyer/advertisements/demo-preview.webp')),
            hash('sha256', Storage::disk('local')->get('advertisements/bagoo-demo-homepage-banner/image.webp')));
    }
}
