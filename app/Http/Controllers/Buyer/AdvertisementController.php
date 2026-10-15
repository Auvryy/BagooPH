<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Services\Advertisements\AdvertisementDestinationService;
use App\Services\Advertisements\AdvertisementReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class AdvertisementController extends Controller
{
    public function visit(Request $request, string $slug, AdvertisementReader $reader, AdvertisementDestinationService $destinations)
    {
        $publication = $reader->publication($slug, $this->publicationId($request));
        if ($reader->state($publication)['state'] === 'live' && ! $publication->revision->offer_revision_id) {
            $target = $destinations->target($publication->revision);
            if ($target['url'] ?? null) {
                return redirect($target['url'])->header('Cache-Control', 'private, no-store');
            }
        }

        return redirect()->route('promotions.show', ['slug' => $slug, 'publication' => $publication->id])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $slug, AdvertisementReader $reader)
    {
        return Inertia::render('Buyer/Promotion', $reader->page($reader->publication($slug, $this->publicationId($request))));
    }

    public function artwork(string $token, AdvertisementReader $reader)
    {
        $asset = $reader->publicAsset($token);
        abort_unless(Storage::disk('local')->exists($asset->image_path), 404);

        return Storage::disk('local')->response($asset->image_path, 'advertisement.webp', [
            'Content-Type' => 'image/webp', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    private function publicationId(Request $request): ?int
    {
        $data = $request->validate(['publication' => 'nullable|integer|min:1|max:2147483647']);

        return isset($data['publication']) ? (int) $data['publication'] : null;
    }
}
