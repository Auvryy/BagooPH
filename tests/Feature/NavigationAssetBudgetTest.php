<?php

namespace Tests\Feature;

use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NavigationAssetBudgetTest extends TestCase
{
    public static function settings(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('settings')]
    public function test_initial_assets_load_without_background_loading_of_unrelated_portals(bool $enabled): void
    {
        config(['catalogue.prefetch_enabled' => $enabled]);
        $directory = 'navigation-assets-'.Str::lower(Str::random(12));
        $path = public_path($directory);
        File::ensureDirectoryExists($path);
        $manifest = [
            'resources/js/app.tsx' => ['file' => 'assets/app.js', 'isEntry' => true, 'imports' => ['_shared.js'],
                'dynamicImports' => ['resources/js/Pages/Buyer/Home.tsx', 'resources/js/Pages/Admin/Users.tsx', 'resources/js/Pages/Courier/Profile.tsx']],
            '_shared.js' => ['file' => 'assets/shared.js'],
            'resources/js/Pages/Buyer/Home.tsx' => ['file' => 'assets/home.js', 'isDynamicEntry' => true, 'imports' => ['_shared.js']],
            'resources/js/Pages/Admin/Users.tsx' => ['file' => 'assets/unused-admin.js', 'isDynamicEntry' => true, 'imports' => ['_shared.js']],
            'resources/js/Pages/Courier/Profile.tsx' => ['file' => 'assets/unused-rider.js', 'isDynamicEntry' => true, 'imports' => ['_shared.js']],
        ];
        foreach ($manifest as $key => $chunk) {
            $manifest[$key]['src'] = $key;
        }
        try {
            File::put($path.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            $vite = app(Vite::class)->useBuildDirectory($directory)->useHotFile($path.'/not-running.hot');
            $html = (string) $vite(['resources/js/app.tsx', 'resources/js/Pages/Buyer/Home.tsx']);
            $this->assertStringContainsString('/assets/app.js', $html);
            $this->assertStringContainsString('/assets/home.js', $html);
            $this->assertStringContainsString('rel="modulepreload"', $html);
            $this->assertStringContainsString('/assets/shared.js', $html);
            $this->assertStringNotContainsString('unused-admin.js', $html);
            $this->assertStringNotContainsString('unused-rider.js', $html);
        } finally {
            File::deleteDirectory($path);
        }
    }
}
