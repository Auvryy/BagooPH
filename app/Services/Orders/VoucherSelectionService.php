<?php

namespace App\Services\Orders;

use App\Exceptions\CheckoutException;
use App\Models\Voucher;
use App\Models\VoucherRevision;
use App\Models\VoucherRevisionBalance;
use App\Rules\AsciiPositiveInteger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class VoucherSelectionService
{
    public function normalize(array $input): array
    {
        $code = $input['voucher_code'] ?? $input['code'] ?? null;
        if (is_string($code)) {
            $code = strtoupper(trim($code, ' '));
            $code = $code === '' ? null : $code;
        }
        $data = Validator::make([
            'voucher_code' => $code,
            'platform_voucher_revision_id' => $input['platform_voucher_revision_id'] ?? null,
            'shop_voucher_revision_ids' => $input['shop_voucher_revision_ids'] ?? [],
        ], [
            'voucher_code' => ['nullable', 'string', 'max:50', 'regex:/\A[A-Z0-9_-]+\z/'],
            'platform_voucher_revision_id' => ['nullable', new AsciiPositiveInteger, 'integer', 'min:1', 'max:2147483647'],
            'shop_voucher_revision_ids' => ['array', 'max:1000'],
            'shop_voucher_revision_ids.*' => ['nullable', new AsciiPositiveInteger, 'integer', 'min:1', 'max:2147483647'],
        ])->validate();
        $map = [];
        foreach ($data['shop_voucher_revision_ids'] as $shopId => $revisionId) {
            if (! preg_match('/\A[1-9][0-9]{0,9}\z/', (string) $shopId) || (int) $shopId > 2147483647) {
                throw ValidationException::withMessages(['shop_voucher_revision_ids' => 'Choose vouchers for valid selected shops.']);
            }
            $map[(int) $shopId] = $revisionId === null ? null : (int) $revisionId;
        }
        ksort($map, SORT_NUMERIC);

        return ['voucher_code' => $data['voucher_code'],
            'platform_voucher_revision_id' => $data['platform_voucher_revision_id'] === null ? null : (int) $data['platform_voucher_revision_id'],
            'shop_voucher_revision_ids' => $map];
    }

    /** Resolve first, then take every campaign lock in ID order after the owned Bag/product locks. */
    public function resolve(array $selection, Collection $items, bool $lock = false): Collection
    {
        $map = $selection['shop_voucher_revision_ids'];
        $shopIds = $items->pluck('product.shop_id')->unique();
        foreach (array_keys($map) as $shopId) {
            if (! $shopIds->contains($shopId)) {
                throw new CheckoutException('This voucher is not for the selected shop items.');
            }
        }
        $ids = collect($map)->filter()->push($selection['platform_voucher_revision_id'])->filter();
        $codeVoucher = null;
        if ($selection['voucher_code']) {
            $codeVoucher = Voucher::where('code', $selection['voucher_code'])->first();
            if (! $codeVoucher?->current_revision_id) {
                throw new CheckoutException($codeVoucher ? 'This older voucher needs its funding reviewed before use.' : 'This voucher is unavailable. Remove it or choose another voucher.');
            }
            $ids->push($codeVoucher->current_revision_id);
        }
        if ($ids->unique()->count() !== $ids->count()) {
            throw new CheckoutException('Choose each voucher only once.');
        }
        $revisions = VoucherRevision::whereIn('id', $ids)->orderBy('id')->get();
        if ($revisions->count() !== $ids->count()) {
            throw new CheckoutException('This voucher is unavailable. Remove it or choose another voucher.');
        }
        $vouchers = Voucher::whereIn('id', $revisions->pluck('voucher_id'))->orderBy('id');
        if ($lock) {
            $vouchers->lockForUpdate();
        }
        $vouchers = $vouchers->get()->keyBy('id');
        foreach ($revisions as $revision) {
            $revision->setRelation('voucher', $vouchers->get($revision->voucher_id));
            if ($revision->id === $selection['platform_voucher_revision_id'] && $revision->issuer_type !== 'platform') {
                throw new CheckoutException('Choose a Bagoo offer for the platform voucher.');
            }
            foreach ($map as $shopId => $revisionId) {
                if ($revision->id === $revisionId && ($revision->issuer_type !== 'shop' || $revision->shop_id !== $shopId)) {
                    throw new CheckoutException('This voucher is not for the selected shop items.');
                }
            }
        }
        if ($revisions->where('issuer_type', 'platform')->count() > 1 || $revisions->where('issuer_type', 'shop')->pluck('shop_id')->duplicates()->isNotEmpty()) {
            throw new CheckoutException('Choose one offer per shop and one Bagoo offer for this checkout.');
        }
        if ($lock) {
            $balances = VoucherRevisionBalance::whereIn('voucher_revision_id', $ids)->orderBy('voucher_revision_id')->lockForUpdate()->get()->keyBy('voucher_revision_id');
            foreach ($revisions as $revision) {
                $revision->setRelation('balance', $balances->get($revision->id));
            }
        }

        return $revisions;
    }
}
