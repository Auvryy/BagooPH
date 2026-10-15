<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HandlesAdvertisementWrites;
use App\Models\AdvertisementAsset;
use App\Services\Advertisements\AdvertisementReportingService;
use App\Services\Advertisements\PromotionPresentation;
use App\Services\Advertisements\SellerPromotionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class PromotionController extends Controller
{
    use HandlesAdvertisementWrites;

    public function report(Request $request, AdvertisementReportingService $reports)
    {
        return Inertia::render('Shared/AdvertisementPerformance', [...$reports->report($request->user(), $request, seller: true), 'baseUrl' => $this->baseUrl($request)]);
    }

    public function index(Request $request, PromotionPresentation $view)
    {
        return Inertia::render('Seller/Promotions/Index', [...$view->listing($request->user(), $request), 'baseUrl' => $this->baseUrl($request)]);
    }

    public function create(Request $request, PromotionPresentation $view)
    {
        return Inertia::render('Seller/Promotions/Edit', [...$view->sellerEditor($request->user()), 'baseUrl' => $this->baseUrl($request)]);
    }

    public function show(Request $request, string $advertisement, SellerPromotionService $promotions, PromotionPresentation $view)
    {
        return Inertia::render('Seller/Promotions/Edit', [...$view->sellerEditor($request->user(), $promotions->find($request->user(), (int) $advertisement)), 'baseUrl' => $this->baseUrl($request)]);
    }

    public function store(Request $request, SellerPromotionService $promotions)
    {
        return $this->write($request, fn () => $promotions->saveDraft($request->user(), $request->all()), 'Promotion draft saved. Submit it when you are ready for review.');
    }

    public function update(Request $request, string $advertisement, SellerPromotionService $promotions)
    {
        return $this->write($request, fn () => $promotions->saveDraft($request->user(), $request->all(), $promotions->find($request->user(), (int) $advertisement)), 'New draft saved. Published content keeps its original reviewed version.');
    }

    public function action(Request $request, string $advertisement, SellerPromotionService $promotions)
    {
        return $this->write($request, fn () => $promotions->act($request->user(), $promotions->find($request->user(), (int) $advertisement), $request->all()), 'Promotion update saved. Review decisions and display history remain recorded.');
    }

    public function preview(Request $request, string $advertisement, SellerPromotionService $promotions, PromotionPresentation $view)
    {
        $input = $request->validate(['revision' => 'nullable|integer|min:1|max:2147483647']);

        return Inertia::render('Seller/Promotions/Preview', [...$view->sellerPreview($request->user(), $promotions->find($request->user(), (int) $advertisement), isset($input['revision']) ? (int) $input['revision'] : null), 'baseUrl' => $this->baseUrl($request)]);
    }

    public function artwork(Request $request, string $asset, SellerPromotionService $promotions)
    {
        $shop = $promotions->shop($request->user());
        abort_unless($promotions->installed(), 404);
        $record = AdvertisementAsset::whereKey((int) $asset)->whereIn('id', function ($query) use ($shop, $request) {
            $query->select('asset_id')->from('advertisement_revisions')->whereIn('advertisement_id', function ($ads) use ($shop, $request) {
                $ads->select('id')->from('advertisements')->where('shop_id', $shop->id)->where('created_by_id', $request->user()->id);
            });
        })->firstOrFail();
        abort_unless(Storage::disk('local')->exists($record->image_path), 404);

        return Storage::disk('local')->response($record->image_path, 'promotion.webp', [
            'Content-Type' => 'image/webp', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow',
        ], 'inline');
    }

    private function baseUrl(Request $request): string
    {
        return $request->is('seller/*') ? '/seller/promotions' : '/promotions';
    }
}
