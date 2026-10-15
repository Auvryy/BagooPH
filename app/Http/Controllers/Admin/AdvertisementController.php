<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdvertisementAsset;
use App\Services\Advertisements\AdvertisementAdminService;
use App\Services\Advertisements\AdvertisementManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AdvertisementController extends Controller
{
    public function index(Request $request, AdvertisementAdminService $ads)
    {
        return Inertia::render('Admin/Advertisements/Index', [...$ads->listing($request->user(), $request), 'baseUrl' => $this->baseUrl($request)]);
    }

    public function create(Request $request, AdvertisementAdminService $ads)
    {
        return Inertia::render('Admin/Advertisements/Edit', [...$ads->editor($request->user()), 'baseUrl' => $this->baseUrl($request)]);
    }

    public function show(Request $request, string $advertisement, AdvertisementAdminService $ads)
    {
        return Inertia::render('Admin/Advertisements/Edit', [...$ads->editor($request->user(), $ads->find($request->user(), (int) $advertisement)),
            'baseUrl' => $this->baseUrl($request)]);
    }

    public function store(Request $request, AdvertisementManager $manager)
    {
        return $this->write($request, fn () => $manager->saveDraft($request->user(), $request->all()), 'Draft saved. It is not public until published.');
    }

    public function update(Request $request, string $advertisement, AdvertisementManager $manager, AdvertisementAdminService $ads)
    {
        return $this->write($request, fn () => $manager->saveDraft($request->user(), $request->all(), $ads->find($request->user(), (int) $advertisement)),
            'New draft saved. Existing published artwork keeps its reviewed content.');
    }

    public function action(Request $request, string $advertisement, AdvertisementManager $manager, AdvertisementAdminService $ads)
    {
        return $this->write($request, fn () => $manager->act($request->user(), $ads->find($request->user(), (int) $advertisement), $request->all()),
            'Advertisement decision saved with its original history.');
    }

    public function import(Request $request, AdvertisementManager $manager)
    {
        return $this->write($request, fn () => $manager->importArtwork($request->user(), $request->all()),
            'Artwork imported as an unpublished candidate. Complete its destination and verify every claim before publishing.');
    }

    public function preview(Request $request, string $advertisement, AdvertisementAdminService $ads)
    {
        $input = $request->validate(['revision' => 'nullable|integer|min:1|max:2147483647']);

        return Inertia::render('Admin/Advertisements/Preview', [...$ads->preview($request->user(), $ads->find($request->user(), (int) $advertisement),
            isset($input['revision']) ? (int) $input['revision'] : null), 'baseUrl' => $this->baseUrl($request)]);
    }

    public function artwork(Request $request, string $asset, AdvertisementManager $manager)
    {
        $manager->authorize($request->user());
        abort_unless($manager->installed(), 404);
        $record = AdvertisementAsset::findOrFail((int) $asset);
        abort_unless(Storage::disk('local')->exists($record->image_path), 404);

        return Storage::disk('local')->response($record->image_path, 'advertisement.webp', [
            'Content-Type' => 'image/webp', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow',
        ], 'inline');
    }

    private function write(Request $request, callable $work, string $message)
    {
        try {
            $ad = $work();

            return redirect($this->baseUrl($request).'/'.$ad->id)->with('success', $message);
        } catch (HttpException $exception) {
            if (! $request->header('X-Inertia') || ! in_array($exception->getStatusCode(), [409, 422], true)) {
                throw $exception;
            }

            return back()->withErrors(['advertisement' => $exception->getMessage()]);
        }
    }

    private function baseUrl(Request $request): string
    {
        return $request->is('admin/*') ? '/admin/advertisements' : '/advertisements';
    }
}
