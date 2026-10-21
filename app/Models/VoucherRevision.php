<?php

namespace App\Models;

use App\Services\Vouchers\VoucherProductScopeService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class VoucherRevision extends VoucherEvidence
{
    protected $casts = ['value_cents' => 'integer', 'minimum_cents' => 'integer', 'maximum_cents' => 'integer',
        'budget_cents' => 'integer', 'percentage_bps' => 'integer', 'global_limit' => 'integer', 'per_buyer_limit' => 'integer',
        'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'shop_id' => 'integer', 'product_ids' => 'array'];

    protected static function booted(): void
    {
        parent::booted();
        static::creating(function (self $revision) {
            $voucher = Voucher::findOrFail($revision->voucher_id);
            $creator = User::findOrFail($revision->created_by_id);
            $data = $revision->getAttributes();
            Validator::make($data, [
                'issuer_type' => ['required', Rule::in(['shop', 'platform'])],
                'funding_party' => ['required', Rule::in([$revision->issuer_type])],
                'discount_type' => ['required', Rule::in($revision->issuer_type === 'shop' ? ['fixed', 'percent'] : ['fixed', 'percent', 'free_shipping'])],
                'revision_number' => 'required|integer|min:1', 'code' => 'required|string|max:50|regex:/\A[A-Z0-9_-]+\z/',
                'name' => 'required|string|max:100', 'description' => 'nullable|string|max:1000',
                'value_cents' => 'required|integer|min:0|max:99999999999',
                'minimum_cents' => 'required|integer|min:0|max:99999999999',
                'maximum_cents' => 'required|integer|min:1|max:99999999999',
                'budget_cents' => 'required|integer|min:1|max:99999999999',
                'percentage_bps' => $revision->discount_type === 'percent' ? 'required|integer|min:1|max:10000' : 'prohibited',
                'global_limit' => 'required|integer|min:1|max:1000000',
                'per_buyer_limit' => 'required|integer|min:1|lte:global_limit',
                'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at',
            ])->validate();
            abort_unless($voucher->issuer_type === $revision->issuer_type && $voucher->shop_id === $revision->shop_id
                && $voucher->code === $revision->code && $creator->canAccessPortal() && $creator->email_verified_at !== null
                && ($creator->isAdmin() || ($revision->issuer_type === 'shop' && $creator->isSeller()
                    && Shop::whereKey($revision->shop_id)->where('user_id', $creator->id)->exists()))
                && ($revision->issuer_type === 'shop' ? $revision->shop_id !== null : $revision->shop_id === null), 403);
            if ($revision->discount_type === 'fixed') {
                Validator::make($data, ['value_cents' => 'required|integer|min:1'])->validate();
            }
            $scope = app(VoucherProductScopeService::class)->validate([
                'product_scope' => $revision->product_scope ?? 'all', 'product_ids' => $revision->product_ids ?? [],
            ], $revision->issuer_type === 'shop' ? $revision->shop_id : null);
            $revision->fill($scope);
        });
        static::created(fn (self $revision) => $revision->balance()->create([]));
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function savedVouchers(): HasMany
    {
        return $this->hasMany(SavedVoucher::class, 'voucher_revision_id');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(VoucherRedemption::class, 'voucher_revision_id');
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function balance(): HasOne
    {
        return $this->hasOne(VoucherRevisionBalance::class);
    }

    public function budgetIncreases(): HasMany
    {
        return $this->hasMany(VoucherCampaignAction::class)->where('action', 'budget_increased');
    }

    public function approvedBudget(): int
    {
        return $this->budget_cents + (int) ($this->budget_increases_sum_budget_increase_cents ?? $this->budgetIncreases()->sum('budget_increase_cents'));
    }

    public function terms(): array
    {
        return $this->only(['id', 'voucher_id', 'revision_number', 'issuer_type', 'shop_id', 'funding_party', 'created_by_id',
            'code', 'name', 'description', 'discount_type', 'value_cents', 'percentage_bps', 'minimum_cents', 'maximum_cents',
            'budget_cents', 'global_limit', 'per_buyer_limit']) + ['product_scope' => $this->product_scope ?? 'all',
                'product_ids' => $this->product_ids ?? [], 'starts_at' => $this->starts_at->toIso8601String(), 'ends_at' => $this->ends_at->toIso8601String()];
    }
}
