<?php

namespace Tests\Feature\Seeders;

use App\Models\Advertisement;
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
        $this->assertCount(1, app(AdvertisementReader::class)->homepage(false));
        $this->assertDatabaseCount('advertisement_actions', 2);
        $this->assertDatabaseCount('orders', 0);
        $this->assertTrue(Storage::disk('local')->exists('advertisements/bagoo-demo-homepage-banner/image.webp'));

        $publication = AdvertisementPublication::where('advertisement_id', $advertisement->id)->sole();
        $this->seed(AdvertisementDemoSeeder::class);
        $this->assertDatabaseCount('advertisements', 1);
        $this->assertDatabaseCount('advertisement_actions', 2);
        $this->assertCount(1, app(AdvertisementReader::class)->homepage(false));

        $publication->update(['ended_at' => now()]);
        $this->seed(AdvertisementDemoSeeder::class);
        $this->assertNotNull($publication->fresh()->ended_at);
        $this->assertSame([], app(AdvertisementReader::class)->homepage(false));
        $this->assertDatabaseCount('orders', 0);
    }
}
