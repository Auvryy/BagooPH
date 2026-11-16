<?php

namespace App\Services\Promotions;

use App\Models\Product;
use App\Models\ProductSale;
use App\Models\SaleEvent;
use App\Models\SaleEventClaim;
use App\Models\SaleEventVoucher;
use App\Models\SavedVoucher;
use App\Models\Shop;
use App\Models\User;
use App\Models\Voucher;
use App\Rules\AsciiPositiveInteger;
use App\Services\BuyerAccessService;
use App\Services\Commerce\ProductSaleService;
use App\Services\ShopEligibilityService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sale events: admins schedule the event; admins and sellers add claimable vouchers; sellers put their products
 * on discount for the event's exact window. Claims are serialized per voucher so a limit is never exceeded.
 */
class SaleEventService
{
    public const MAX_DAYS = 31;

    public const ZONE = 'Asia/Manila';

    /** Create or update an event. Only an admin calls this. */
    public function save(User $admin, array $input, ?UploadedFile $banner, ?SaleEvent $event = null): SaleEvent
    {
        abort_unless($admin->isAdmin(), 403);
        $data = Validator::make($input, [
            'title' => ['required', 'string', 'max:80'],
            'badge' => ['required', 'string', 'max:24'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i', 'after:starts_at'],
        ], [
            'badge.required' => 'Add a short label, for example 11.11 DEAL.',
            'ends_at.after' => 'The event must end after it starts.',
        ])->validate();
        if ($banner) {
            Validator::make(['banner' => $banner], ['banner' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:min_width=800,min_height=200']],
                ['banner.dimensions' => 'Use a wide banner at least 800 × 200 pixels.', 'banner.max' => 'Use a banner under 4 MB.'])->validate();
        }
        $starts = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['starts_at'], self::ZONE)->utc();
        $ends = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['ends_at'], self::ZONE)->utc();
        if ($starts->diffInDays($ends) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['ends_at' => 'Keep an event to '.self::MAX_DAYS.' days or less.']);
        }
        if ($ends->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages(['ends_at' => 'Choose an end time in the future.']);
        }

        return DB::transaction(function () use ($admin, $data, $banner, $event, $starts, $ends) {
            if ($event) {
                $event = SaleEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();
                if ($event->status() === 'ended') {
                    throw ValidationException::withMessages(['event' => 'This event has ended. Create a new event instead.']);
                }
                if ($event->status() === 'live' && ! $starts->equalTo($event->starts_at)) {
                    throw ValidationException::withMessages(['starts_at' => 'The event is live, so its start time is fixed. You can still change the end time.']);
                }
            } elseif ($starts->lessThan(now()->startOfMinute())) {
                throw ValidationException::withMessages(['starts_at' => 'Choose the current minute or a later start time.']);
            }
            $path = $banner?->store('sale-events', 'public');
            if ($path) {
                DB::afterRollBack(fn () => Storage::disk('public')->delete($path));
            }
            $values = ['title' => trim($data['title']), 'badge' => Str::upper(trim($data['badge'])), 'starts_at' => $starts, 'ends_at' => $ends];
            if ($path) {
                $values['banner_path'] = $path;
            }
            if (! $event) {
                return SaleEvent::create([...$values, 'slug' => $this->slug($values['title']), 'created_by' => $admin->id]);
            }
            $event->update($values);
            // Product discounts follow the event window so buyers never see a sale outlive its event.
            ProductSale::where('sale_event_id', $event->id)->whereNull('ended_at')->get()->each(function (ProductSale $sale) use ($starts, $ends) {
                $sale->update(['starts_at' => $sale->starts_at->lessThanOrEqualTo(now()) ? $sale->starts_at : $starts, 'ends_at' => $ends]);
            });

            return $event;
        });
    }

    public function end(User $admin, SaleEvent $event): void
    {
        abort_unless($admin->isAdmin(), 403);
        DB::transaction(function () use ($event) {
            $event = SaleEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();
            if ($event->status() === 'ended') {
                return;
            }
            $event->update(['ended_at' => now()]);
            ProductSale::where('sale_event_id', $event->id)->whereNull('ended_at')->update(['ended_at' => now(), 'updated_at' => now()]);
        });
    }

    /** Admin adds a Bagoo voucher; a seller adds one of their shop's vouchers. */
    public function addVoucher(User $actor, SaleEvent $event, array $input, ?Shop $shop = null): SaleEventVoucher
    {
        $data = Validator::make($input, [
            'voucher_id' => ['required', new AsciiPositiveInteger, 'integer'],
            'claim_limit' => ['nullable', new AsciiPositiveInteger, 'integer', 'between:1,1000000'],
        ], ['voucher_id.required' => 'Choose a voucher to add.', 'claim_limit.*' => 'Enter how many buyers can claim it, or leave it empty for everyone.'])->validate();

        return DB::transaction(function () use ($actor, $event, $data, $shop) {
            $event = SaleEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();
            if ($event->status() === 'ended') {
                throw ValidationException::withMessages(['voucher_id' => 'This event has ended.']);
            }
            $voucher = Voucher::with('currentRevision')->whereKey($data['voucher_id'])->first();
            $owned = $actor->isAdmin() ? $voucher?->issuer_type === 'platform' : ($shop && $voucher?->issuer_type === 'shop' && $voucher->shop_id === $shop->id);
            if (! $voucher || ! $owned) {
                throw ValidationException::withMessages(['voucher_id' => 'Choose one of your own vouchers.']);
            }
            $revision = $voucher->currentRevision;
            if (! $voucher->is_active || $voucher->archived_at || ! $revision || $revision->ends_at->lessThanOrEqualTo(now())) {
                throw ValidationException::withMessages(['voucher_id' => 'This voucher is paused or has ended. Choose a running voucher.']);
            }
            if (SaleEventVoucher::where('sale_event_id', $event->id)->where('voucher_id', $voucher->id)->exists()) {
                throw ValidationException::withMessages(['voucher_id' => 'This voucher is already in the event.']);
            }

            return SaleEventVoucher::create(['sale_event_id' => $event->id, 'voucher_id' => $voucher->id, 'shop_id' => $voucher->shop_id,
                'claim_limit' => $data['claim_limit'] ?? null, 'added_by' => $actor->id]);
        });
    }

    public function removeVoucher(User $actor, SaleEventVoucher $entry, ?Shop $shop = null): void
    {
        DB::transaction(function () use ($actor, $entry, $shop) {
            $entry = SaleEventVoucher::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->isAdmin() || ($shop && $entry->shop_id === $shop->id), 403);
            if ($entry->claimed_count > 0) {
                throw ValidationException::withMessages(['voucher' => 'Buyers already claimed this voucher, so it stays in the event. Pause the voucher to stop new orders.']);
            }
            $entry->delete();
        });
    }

    /**
     * A seller puts products on discount for the event. Each product gets one percentage sale for the event window,
     * using the regular sale-price rules, so checkout charges exactly what the event page shows.
     *
     * @param  list<int>  $productIds
     */
    public function addProducts(Shop $shop, SaleEvent $event, array $productIds, int $percentage): int
    {
        if ($percentage < 1 || $percentage > 90) {
            throw ValidationException::withMessages(['percentage' => 'Enter a discount from 1% to 90%.']);
        }
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), fn ($id) => $id > 0)));
        if ($productIds === []) {
            throw ValidationException::withMessages(['product_ids' => 'Choose at least one product.']);
        }
        if (count($productIds) > 200) {
            throw ValidationException::withMessages(['product_ids' => 'Add up to 200 products at a time.']);
        }
        $event = SaleEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();
        if ($event->status() === 'ended') {
            throw ValidationException::withMessages(['product_ids' => 'This event has ended.']);
        }
        $starts = $event->starts_at->greaterThan(now()) ? $event->starts_at : now()->toImmutable();
        $input = ['kind' => 'store', 'name' => Str::limit($event->title, 100, ''), 'discount_type' => 'percentage', 'percentage' => $percentage,
            'starts_at' => $starts->setTimezone(self::ZONE)->format('Y-m-d\TH:i'),
            'ends_at' => $event->closesAt()->setTimezone(self::ZONE)->format('Y-m-d\TH:i')];
        $products = Product::whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get();
        if ($products->count() !== count($productIds) || $products->contains(fn ($product) => $product->shop_id !== $shop->id)) {
            throw ValidationException::withMessages(['product_ids' => 'Choose products from your own shop.']);
        }
        foreach ($products as $product) {
            if (! app(ShopEligibilityService::class)->productIsEligible($product)) {
                throw ValidationException::withMessages(['product_ids' => "“{$product->name}” is not live in your shop, so it cannot join."]);
            }
            if (ProductSale::where('product_id', $product->id)->where('sale_event_id', $event->id)->whereNull('ended_at')->exists()) {
                throw ValidationException::withMessages(['product_ids' => "“{$product->name}” is already in this event."]);
            }
            try {
                $sale = app(ProductSaleService::class)->save($product, $input);
            } catch (ValidationException $error) {
                $first = collect($error->errors())->flatten()->first();
                throw ValidationException::withMessages(['product_ids' => "“{$product->name}”: {$first}"]);
            }
            $sale->forceFill(['sale_event_id' => $event->id])->save();
        }

        return $products->count();
    }

    public function removeProduct(User $actor, ProductSale $sale, ?Shop $shop = null): void
    {
        DB::transaction(function () use ($actor, $sale, $shop) {
            $sale = ProductSale::with('product')->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            abort_unless($sale->sale_event_id && ($actor->isAdmin() || ($shop && $sale->product?->shop_id === $shop->id)), 403);
            if (! $sale->ended_at) {
                $sale->update(['ended_at' => now()]);
            }
        });
    }

    /**
     * Claim one event voucher. The event-voucher row lock serializes concurrent claims, and the unique
     * (voucher, buyer) index is the final guard, so a limit can never be exceeded and a buyer never claims twice.
     *
     * @return array{claimed: bool, message: string}
     */
    public function claim(User $actor, SaleEventVoucher $entry): array
    {
        $buyer = app(BuyerAccessService::class)->requirePortal($actor);
        try {
            return DB::transaction(function () use ($buyer, $entry) {
                $entry = SaleEventVoucher::with(['event', 'voucher.currentRevision'])->whereKey($entry->id)->lockForUpdate()->firstOrFail();
                if (SaleEventClaim::where('sale_event_voucher_id', $entry->id)->where('buyer_id', $buyer->id)->exists()) {
                    return ['claimed' => true, 'message' => 'Already claimed. It applies at checkout.'];
                }
                if ($entry->event->status() !== 'live') {
                    throw ValidationException::withMessages(['voucher' => $entry->event->status() === 'upcoming' ? 'This event has not started yet.' : 'This event has ended.']);
                }
                $voucher = $entry->voucher;
                $revision = $voucher?->currentRevision;
                if (! $voucher || ! $voucher->is_active || $voucher->archived_at || ! $revision || $revision->ends_at->lessThanOrEqualTo(now())) {
                    throw ValidationException::withMessages(['voucher' => 'This voucher is no longer available.']);
                }
                if ($entry->claim_limit !== null && $entry->claimed_count >= $entry->claim_limit) {
                    throw ValidationException::withMessages(['voucher' => 'All vouchers have been claimed.']);
                }
                SaleEventClaim::create(['sale_event_voucher_id' => $entry->id, 'buyer_id' => $buyer->id]);
                $entry->increment('claimed_count');
                SavedVoucher::firstOrCreate(['buyer_id' => $buyer->id, 'voucher_revision_id' => $revision->id]);

                return ['claimed' => true, 'message' => 'Voucher claimed. It applies at checkout.'];
            }, 3);
        } catch (UniqueConstraintViolationException) {
            return ['claimed' => true, 'message' => 'Already claimed. It applies at checkout.'];
        }
    }

    /** @return array{claimed: int, unavailable: int} */
    public function claimAll(User $actor, SaleEvent $event): array
    {
        $claimed = $unavailable = 0;
        foreach ($event->vouchers()->orderBy('id')->get() as $entry) {
            try {
                $this->claim($actor, $entry);
                $claimed++;
            } catch (ValidationException) {
                $unavailable++;
            }
        }

        return ['claimed' => $claimed, 'unavailable' => $unavailable];
    }

    private function slug(string $title): string
    {
        $base = Str::limit(Str::slug(str_replace('.', '-', $title)) ?: 'sale', 60, '');
        $slug = $base;
        for ($i = 2; SaleEvent::where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
