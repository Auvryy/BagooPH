<?php

namespace App\Services\Orders;

use App\Exceptions\CheckoutException;
use App\Models\LogisticsCompany;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Support\Collection;

class VoucherPreviewService
{
    /** Only preview may clear invalid slots. Final placement always rejects changed availability. */
    public function preview(User $buyer, Collection $items, string $deliveryType, array $input, ?LogisticsCompany $company = null): array
    {
        $resolver = app(VoucherSelectionService::class);
        $selection = $resolver->normalize($input);
        $shopIds = $items->pluck('product.shop_id')->unique();
        foreach (array_keys($selection['shop_voucher_revision_ids']) as $id) {
            if (! $shopIds->contains($id)) {
                throw new CheckoutException('This voucher is not for the selected shop items.');
            }
        }
        $errors = [];
        if ($selection['voucher_code']) {
            $voucher = Voucher::with('currentRevision')->where('code', $selection['voucher_code'])->first();
            $revision = $voucher?->currentRevision;
            if (! $revision) {
                $errors['code'] = $voucher ? 'This older voucher needs its funding reviewed before use.' : 'This voucher is unavailable. Remove it or choose another voucher.';
            } elseif ($revision->issuer_type === 'shop' && ! $shopIds->contains($revision->shop_id)) {
                $errors['code'] = 'This voucher is not for the selected shop items.';
            } elseif ($revision->issuer_type === 'platform') {
                $selection['platform_voucher_revision_id'] = $revision->id;
            } else {
                $selection['shop_voucher_revision_ids'][$revision->shop_id] = $revision->id;
            }
        }
        $selection['voucher_code'] = null;
        $revisions = collect();
        $slots = ['platform' => $selection['platform_voucher_revision_id']];
        foreach ($selection['shop_voucher_revision_ids'] as $shopId => $id) {
            $slots['shop:'.$shopId] = $id;
        }
        foreach ($slots as $slot => $id) {
            if (! $id) {
                continue;
            }
            try {
                $single = ['voucher_code' => null, 'platform_voucher_revision_id' => $slot === 'platform' ? $id : null,
                    'shop_voucher_revision_ids' => $slot === 'platform' ? [] : [(int) substr($slot, 5) => $id]];
                $revisions = $revisions->concat($resolver->resolve($single, $items));
            } catch (CheckoutException $exception) {
                $errors[$slot] = $exception->getMessage();
                $this->clear($selection, $slot);
            }
        }
        $quotes = app(CheckoutQuoteService::class);
        do {
            $quote = $quotes->quote($items, $deliveryType, $revisions, $buyer, $company);
            $failed = isset($quote['failed_revision_id']) ? $revisions->firstWhere('id', $quote['failed_revision_id']) : null;
            if ($failed) {
                $slot = $failed->issuer_type === 'platform' ? 'platform' : 'shop:'.$failed->shop_id;
                $errors[$slot] = $quote['voucher_error'];
                $this->clear($selection, $slot);
                $revisions = $revisions->reject(fn ($r) => $r->id === $failed->id)->values();
            }
        } while ($failed);
        unset($quote['failed_revision_id']);
        $quote['voucher_error'] = $errors ? reset($errors) : $quote['voucher_error'];
        ksort($selection['shop_voucher_revision_ids'], SORT_NUMERIC);

        return ['quote' => $quote, 'validSelection' => $selection, 'selectionErrors' => $errors, 'revisions' => $revisions];
    }

    private function clear(array &$selection, string $slot): void
    {
        if ($slot === 'platform') {
            $selection['platform_voucher_revision_id'] = null;
        } else {
            $selection['shop_voucher_revision_ids'][(int) substr($slot, 5)] = null;
        }
    }
}
