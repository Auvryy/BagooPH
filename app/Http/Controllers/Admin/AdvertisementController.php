<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HandlesAdvertisementWrites;
use App\Models\AdvertisementAsset;
use App\Services\Advertisements\AdvertisementAdminService;
use App\Services\Advertisements\AdvertisementInput;
use App\Services\Advertisements\AdvertisementManager;
use App\Services\Advertisements\AdvertisementReportingService;
use App\Services\Advertisements\PromotionPresentation;
use App\Services\Advertisements\SellerPromotionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdvertisementController extends Controller
{
    use HandlesAdvertisementWrites;

    public function report(Request $request, AdvertisementReportingService $reports)
    {
        return Inertia::render('Shared/AdvertisementPerformance', [...$reports->report($request->user(), $request), 'baseUrl' => $this->baseUrl($request)]);
    }

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
        $ad = $ads->find($request->user(), (int) $advertisement);
        if ($ad->shop_id) {
            return Inertia::render('Admin/Advertisements/Review', [...app(PromotionPresentation::class)->adminEditor($request->user(), $ad), 'baseUrl' => $this->baseUrl($request)]);
        }

        return Inertia::render('Admin/Advertisements/Edit', [...$ads->editor($request->user(), $ad),
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
        $input = $request->validate(['revision' => 'nullable|integer|min:1|max:2147483647', 'slot' => ['nullable', Rule::in(AdvertisementInput::SLOTS)]]);

        return Inertia::render('Admin/Advertisements/Preview', [...$ads->preview($request->user(), $ads->find($request->user(), (int) $advertisement),
            isset($input['revision']) ? (int) $input['revision'] : null, $input['slot'] ?? null), 'baseUrl' => $this->baseUrl($request)]);
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

    public function review(Request $request, string $advertisement, AdvertisementAdminService $ads, SellerPromotionService $promotions)
    {
        return $this->write($request, fn () => $promotions->decide($request->user(), $ads->find($request->user(), (int) $advertisement), $request->all()),
            'Review saved for the submitted revision. Homepage placement remains a separate decision.');
    }

    private function baseUrl(Request $request): string
    {
        return $request->is('admin/*') ? '/admin/advertisements' : '/advertisements';
    }
}
