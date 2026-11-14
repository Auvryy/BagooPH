<?php

namespace App\Services\Governance;

use App\Models\Delivery;
use App\Models\DispatchEscalation;
use App\Models\FinancialPenalty;
use App\Models\HubPickupPause;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\ModerationSignal;
use App\Models\ScaleDiscrepancyLog;
use App\Models\Shop;
use App\Models\SlaClock;
use App\Models\SlaPause;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Services\Logistics\LogisticsRateService;
use App\Services\Notifications\NotificationDeliveryService;
use App\Services\Wallet\WalletLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FinancialPenaltyService
{
    public const DEFAULT_SLA_PENALTY_CENTS = 15000; // PHP 150.00

    public function __construct(
        private readonly WalletLedger $walletLedger,
    ) {}

    public function computeTariffCents(int $weightGrams, ?LogisticsCompany $company = null): int
    {
        $included = (int) ($company?->included_weight_grams ?? LogisticsRateService::DEFAULT_INCLUDED_GRAMS);
        $base = (int) ($company?->base_shipping_cents ?? LogisticsRateService::DEFAULT_BASE_CENTS);
        $additional = (int) ($company?->additional_kg_cents ?? LogisticsRateService::DEFAULT_ADDITIONAL_KG_CENTS);
        $extraKg = (int) ceil(max(0, $weightGrams - max(1, $included)) / 1000);

        return $base + ($extraKg * $additional);
    }

    public function proposeSlaPickupPenalty(User $actor, SlaClock $clock, array $input = []): FinancialPenalty
    {
        $actor = User::findOrFail($actor->id);
        abort_unless(
            $actor->canAccessPortal() && ($actor->isAdmin() || $actor->isLogistics()),
            403,
            'Only verified administrators or logistics operators may propose an SLA penalty.'
        );

        $data = Validator::make($input, [
            'amount_cents' => ['nullable', 'integer', 'min:100', 'max:5000000'],
            'reason' => ['nullable', 'string', new ApplicationText('notes', 5, 1000)],
            'evidence_summary' => ['nullable', 'string', new ApplicationText('notes', 5, 1000)],
            'evidence_basis' => ['nullable', 'string', 'max:64'],
        ])->validate();

        if (($data['evidence_basis'] ?? null) === 'last_scan_only') {
            throw ValidationException::withMessages([
                'evidence_summary' => 'Last scan alone does not establish fault or justify a financial penalty.',
            ]);
        }

        return DB::transaction(function () use ($actor, $clock, $data) {
            $lockedClock = SlaClock::whereKey($clock->id)->lockForUpdate()->firstOrFail();
            $delivery = $lockedClock->delivery_id
                ? Delivery::with(['order.items.shop', 'originBayanHub'])->whereKey($lockedClock->delivery_id)->lockForUpdate()->first()
                : null;

            if (! $delivery) {
                throw ValidationException::withMessages([
                    'sla_clock_id' => 'A verified delivery record is required before proposing an SLA pickup penalty.',
                ]);
            }

            if ($lockedClock->status === 'paused' || $lockedClock->pause_exempt) {
                throw ValidationException::withMessages([
                    'sla_clock_id' => 'Geo-fenced Force-Majeure or paused SLA clocks cannot incur financial penalties.',
                ]);
            }

            if ($lockedClock->status !== 'breached' && (! $lockedClock->breach_at || $lockedClock->breach_at->isFuture())) {
                throw ValidationException::withMessages([
                    'sla_clock_id' => 'This SLA clock has not breached yet.',
                ]);
            }

            $escalation = DispatchEscalation::where('delivery_id', $delivery->id)->first();
            if ($escalation && ($escalation->penalty_exempt
                || in_array($escalation->coverage_outcome, ['no_service_coverage', 'pickup_paused', 'no_available_rider'], true))) {
                throw ValidationException::withMessages([
                    'sla_clock_id' => 'Orders marked penalty-exempt, pickup-paused, or without service coverage cannot incur SLA penalties.',
                ]);
            }

            $hubId = $lockedClock->logistics_hub_id ?? $delivery->origin_bayan_hub_id;
            $hub = $hubId ? LogisticsHub::find($hubId) : null;
            $companyId = $lockedClock->logistics_company_id ?? $delivery->logistics_company_id ?? $hub?->logistics_company_id;
            $company = $companyId ? LogisticsCompany::with('user')->find($companyId) : null;

            if (! $company) {
                throw ValidationException::withMessages([
                    'sla_clock_id' => 'No logistics company could be verified on this breached pickup clock.',
                ]);
            }

            $breachMoment = $lockedClock->breach_at ?? now();
            $pickupPaused = $hub && HubPickupPause::where('logistics_hub_id', $hub->id)
                ->where('paused_at', '<=', $breachMoment)
                ->where(fn (Builder $q) => $q->where('status', 'active')->orWhere('resumed_at', '>=', $breachMoment))
                ->exists();

            if ($pickupPaused) {
                throw ValidationException::withMessages([
                    'sla_clock_id' => 'Origin hub pickup was paused during the SLA window; financial penalty rejected.',
                ]);
            }

            $forceMajeurePause = SlaPause::where('starts_at', '<=', $breachMoment)
                ->where(fn (Builder $q) => $q->where('status', 'active')->orWhere('resumed_at', '>=', $breachMoment))
                ->where(fn (Builder $scope) => $scope
                    ->where('scope_type', 'platform')
                    ->when($hub?->province, fn (Builder $q, string $province) => $q->orWhere(fn (Builder $p) => $p
                        ->where('scope_type', 'province')->whereRaw('LOWER(scope_value) = ?', [mb_strtolower($province)])))
                    ->orWhere(fn (Builder $q) => $q->where('scope_type', 'company')->where('logistics_company_id', $company->id))
                    ->when($hub, fn (Builder $q) => $q->orWhere(fn (Builder $h) => $h
                        ->where('scope_type', 'hub')->where('logistics_hub_id', $hub->id))))
                ->exists();

            if ($forceMajeurePause) {
                throw ValidationException::withMessages([
                    'sla_clock_id' => 'An active geo-fenced SLA Force-Majeure pause covers this hub or province.',
                ]);
            }

            if ($hub && ($shop = $delivery->order?->items->first()?->shop) && $shop->pickup_province && $hub->province
                && mb_strtolower(trim($shop->pickup_province)) !== mb_strtolower(trim($hub->province))) {
                throw ValidationException::withMessages([
                    'sla_clock_id' => 'Pickup origin is outside the hub geo-fenced province coverage (no_service_coverage).',
                ]);
            }

            $existing = FinancialPenalty::where('sla_clock_id', $lockedClock->id)
                ->where('category', 'sla_pickup_breach')
                ->whereNotIn('status', ['rejected', 'waived'])
                ->first();
            if ($existing) {
                return $existing;
            }

            $amountCents = (int) ($data['amount_cents'] ?? self::DEFAULT_SLA_PENALTY_CENTS);
            $summary = trim((string) ($data['evidence_summary'] ?? "Verified geo-fenced {$lockedClock->stage} SLA breach for delivery {$delivery->tracking_number} under hub {$hub?->name} with active coverage and no pauses."));

            return FinancialPenalty::create([
                'reference' => 'PEN-'.Str::upper(Str::random(10)),
                'subject_type' => 'company',
                'subject_id' => $company->id,
                'subject_user_id' => $company->user_id,
                'category' => 'sla_pickup_breach',
                'amount_cents' => $amountCents,
                'settled_cents' => 0,
                'outstanding_cents' => $amountCents,
                'status' => 'pending_review',
                'reason_code' => 'sla_pickup_breach',
                'reason' => trim((string) ($data['reason'] ?? 'Geo-fenced pickup SLA breach pending administrator review.')),
                'evidence_summary' => $summary,
                'evidence_refs' => [
                    'sla_clock_id' => $lockedClock->id,
                    'stage' => $lockedClock->stage,
                    'delivery_id' => $delivery->id,
                    'hub_id' => $hub?->id,
                    'breach_at' => $breachMoment->toIso8601String(),
                ],
                'order_id' => $delivery->order_id,
                'delivery_id' => $delivery->id,
                'hub_id' => $hub?->id,
                'sla_clock_id' => $lockedClock->id,
                'proposed_by_id' => $actor->id,
                'proposed_at' => now(),
            ]);
        });
    }

    public function recordScaleDiscrepancy(User $actor, Delivery $delivery, int $measuredWeightGrams, ?string $notes = null): ScaleDiscrepancyLog
    {
        $actor = User::findOrFail($actor->id);
        abort_unless($actor->canAccessPortal() && ($actor->isAdmin() || $actor->isLogistics()), 403);

        if ($measuredWeightGrams < 1 || $measuredWeightGrams > 10000000) {
            throw ValidationException::withMessages(['measured_weight_grams' => 'Enter a valid measured parcel weight in grams.']);
        }

        return DB::transaction(function () use ($actor, $delivery, $measuredWeightGrams, $notes) {
            $delivery->loadMissing('order.items.shop', 'company');
            $order = $delivery->order;
            $shop = $order?->items->first()?->shop;
            $sellerId = $shop?->user_id;
            $company = $delivery->company;

            $measurements = $order?->shipping_quote['measurements'] ?? [];
            $declaredGrams = 0;
            foreach ($measurements as $unit) {
                $qty = max(1, (int) ($unit['quantity'] ?? 1));
                $unitWeight = (float) ($unit['unit_weight_kg'] ?? 0);
                $declaredGrams += (int) round($unitWeight * 1000) * $qty;
            }
            if ($declaredGrams <= 0) {
                $declaredGrams = (int) ($order?->shipping_quote['billable_weight_grams'] ?? $company?->included_weight_grams ?? LogisticsRateService::DEFAULT_INCLUDED_GRAMS);
            }

            $declaredShippingCents = (int) ($order?->shipping_quote['payable_shipping_cents']
                ?? $order?->shipping_quote['shipping_cents']
                ?? ($order ? (int) round((float) $order->shipping_fee * 100) : 0));
            $recalculatedCents = $this->computeTariffCents($measuredWeightGrams, $company);
            $overageGrams = $measuredWeightGrams - $declaredGrams;
            $tariffDeltaCents = max(0, $recalculatedCents - $declaredShippingCents);
            $flagged = $overageGrams >= 500 || $tariffDeltaCents > 0;

            $log = ScaleDiscrepancyLog::create([
                'reference' => 'SCL-'.Str::upper(Str::random(10)),
                'order_id' => $order?->id,
                'delivery_id' => $delivery->id,
                'shop_id' => $shop?->id,
                'seller_id' => $sellerId,
                'hub_id' => $delivery->current_hub_id ?? $delivery->origin_bayan_hub_id,
                'recorded_by_id' => $actor->id,
                'declared_weight_grams' => max(0, $declaredGrams),
                'measured_weight_grams' => $measuredWeightGrams,
                'overage_grams' => $overageGrams,
                'declared_shipping_cents' => max(0, $declaredShippingCents),
                'recalculated_shipping_cents' => max(0, $recalculatedCents),
                'tariff_delta_cents' => $tariffDeltaCents,
                'status' => $flagged ? 'flagged_for_penalty' : 'logged',
                'notes' => $notes ? trim($notes) : null,
                'recorded_at' => now(),
            ]);

            if ($flagged && Schema::hasTable('moderation_signals')) {
                ModerationSignal::create([
                    'reference' => 'SIG-'.Str::upper(Str::random(10)),
                    'signal_type' => 'scale_discrepancy',
                    'severity' => $tariffDeltaCents >= 5000 ? 'high_priority' : 'review_required',
                    'status' => 'open',
                    'is_punitive' => false,
                    'subject_user_id' => $sellerId,
                    'order_id' => $order?->id,
                    'delivery_id' => $delivery->id,
                    'context_payload' => [
                        'scale_discrepancy_log_id' => $log->id,
                        'declared_weight_grams' => $declaredGrams,
                        'measured_weight_grams' => $measuredWeightGrams,
                        'overage_grams' => $overageGrams,
                        'tariff_delta_cents' => $tariffDeltaCents,
                    ],
                    'summary' => "Hub weigh-in discrepancy {$log->reference}: measured {$measuredWeightGrams}g vs declared {$declaredGrams}g (tariff delta PHP ".number_format($tariffDeltaCents / 100, 2).').',
                ]);
            }

            return $log;
        });
    }

    public function proposeScalePenalty(User $actor, ScaleDiscrepancyLog $log, array $input = []): FinancialPenalty
    {
        $actor = User::findOrFail($actor->id);
        abort_unless($actor->canAccessPortal() && ($actor->isAdmin() || $actor->isLogistics()), 403);

        $data = Validator::make($input, [
            'amount_cents' => ['nullable', 'integer', 'min:100', 'max:5000000'],
            'reason' => ['nullable', 'string', new ApplicationText('notes', 5, 1000)],
        ])->validate();

        return DB::transaction(function () use ($actor, $log, $data) {
            $locked = ScaleDiscrepancyLog::whereKey($log->id)->lockForUpdate()->firstOrFail();
            if (! $locked->seller_id) {
                throw ValidationException::withMessages(['scale_discrepancy_log_id' => 'Seller ownership is required before proposing a weigh-in adjustment.']);
            }
            $amountCents = (int) ($data['amount_cents'] ?? $locked->tariff_delta_cents);
            if ($amountCents < 100) {
                throw ValidationException::withMessages(['amount_cents' => 'No chargeable tariff difference exists on this scale log.']);
            }
            if ($locked->financial_penalty_id) {
                return FinancialPenalty::findOrFail($locked->financial_penalty_id);
            }

            $penalty = FinancialPenalty::create([
                'reference' => 'PEN-'.Str::upper(Str::random(10)),
                'subject_type' => 'seller',
                'subject_id' => $locked->seller_id,
                'subject_user_id' => $locked->seller_id,
                'category' => 'scale_weight_overage',
                'amount_cents' => $amountCents,
                'settled_cents' => 0,
                'outstanding_cents' => $amountCents,
                'status' => 'pending_review',
                'reason_code' => 'scale_weight_overage',
                'reason' => trim((string) ($data['reason'] ?? "Tariff delta from hub weigh-in {$locked->reference} ({$locked->overage_grams}g overage).")),
                'evidence_summary' => "Hub weigh-in recorded {$locked->measured_weight_grams}g vs declared {$locked->declared_weight_grams}g; recalculated tariff delta is PHP ".number_format($amountCents / 100, 2).'.',
                'evidence_refs' => ['scale_discrepancy_log_id' => $locked->id, 'delivery_id' => $locked->delivery_id],
                'order_id' => $locked->order_id,
                'delivery_id' => $locked->delivery_id,
                'hub_id' => $locked->hub_id,
                'scale_discrepancy_log_id' => $locked->id,
                'proposed_by_id' => $actor->id,
                'proposed_at' => now(),
            ]);

            $locked->update(['financial_penalty_id' => $penalty->id]);

            return $penalty;
        });
    }

    public function proposeManualPenalty(User $actor, array $input, EnforcementService $enforcement): FinancialPenalty
    {
        $actor = $enforcement->assertVerifiedAdmin($actor);
        $data = Validator::make($input, [
            'subject_type' => ['required', 'string', Rule::in(['seller', 'company', 'courier', 'buyer'])],
            'subject_id' => ['required', 'integer', 'min:1'],
            'category' => ['required', 'string', Rule::in(['off_platform_bypass', 'policy_breach', 'recovery_debt'])],
            'amount_cents' => ['required', 'integer', 'min:100', 'max:5000000'],
            'reason_code' => ['required', 'string', 'max:64'],
            'reason' => ['required', 'string', new ApplicationText('notes', 5, 1000)],
            'evidence_summary' => ['required', 'string', new ApplicationText('notes', 5, 1000)],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'delivery_id' => ['nullable', 'integer', 'exists:deliveries,id'],
            'source_signal_id' => ['nullable', 'integer', 'exists:moderation_signals,id'],
        ])->validate();

        if (! empty($data['source_signal_id'])) {
            $signal = ModerationSignal::findOrFail($data['source_signal_id']);
            if ($signal->signal_type === 'off_platform_bypass_keyword' && $signal->status === 'open') {
                throw ValidationException::withMessages([
                    'source_signal_id' => 'Keyword detection alone does not establish abuse. Complete buyer check-in or confirm corroborating evidence first.',
                ]);
            }
        }

        $subject = $enforcement->resolveSubject($data['subject_type'], (int) $data['subject_id']);
        abort_if($subject['subject_user_id'] === $actor->id, 403, 'You cannot propose a penalty against yourself.');

        return FinancialPenalty::create([
            'reference' => 'PEN-'.Str::upper(Str::random(10)),
            'subject_type' => $subject['subject_type'],
            'subject_id' => $subject['subject_id'],
            'subject_user_id' => $subject['subject_user_id'],
            'category' => $data['category'],
            'amount_cents' => (int) $data['amount_cents'],
            'settled_cents' => 0,
            'outstanding_cents' => (int) $data['amount_cents'],
            'status' => 'pending_review',
            'reason_code' => $data['reason_code'],
            'reason' => trim($data['reason']),
            'evidence_summary' => trim($data['evidence_summary']),
            'evidence_refs' => array_filter(['source_signal_id' => $data['source_signal_id'] ?? null]),
            'order_id' => $data['order_id'] ?? null,
            'delivery_id' => $data['delivery_id'] ?? null,
            'proposed_by_id' => $actor->id,
            'proposed_at' => now(),
        ]);
    }

    public function decidePenalty(User $actor, FinancialPenalty $penalty, array $input, EnforcementService $enforcement): FinancialPenalty
    {
        $actor = $enforcement->assertVerifiedAdmin($actor);
        $data = Validator::make($input, [
            'decision' => ['required', 'string', Rule::in(['approve', 'waive', 'reject'])],
            'decision_notes' => ['required', 'string', new ApplicationText('notes', 5, 1000)],
        ])->validate();

        return DB::transaction(function () use ($actor, $penalty, $data, $enforcement) {
            $locked = FinancialPenalty::whereKey($penalty->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->subject_user_id === $actor->id, 403, 'You cannot adjudicate your own financial penalty.');

            if (! in_array($locked->status, ['pending_review', 'unpaid_debt_hold'], true)) {
                throw ValidationException::withMessages(['decision' => 'This penalty has already reached a final decision.']);
            }

            if (in_array($data['decision'], ['waive', 'reject'], true)) {
                $locked->update([
                    'status' => $data['decision'] === 'waive' ? 'waived' : 'rejected',
                    'outstanding_cents' => 0,
                    'waived_by_id' => $actor->id,
                    'decision_notes' => trim($data['decision_notes']),
                    'decided_at' => now(),
                ]);
                if ($locked->scale_discrepancy_log_id) {
                    ScaleDiscrepancyLog::whereKey($locked->scale_discrepancy_log_id)->update(['status' => 'cleared']);
                }
                $enforcement->syncDebtSanctionClearance($locked->id, $locked->subject_user_id);

                return $locked->fresh();
            }

            $subjectUser = $locked->subject_user_id ? User::whereKey($locked->subject_user_id)->lockForUpdate()->first() : null;
            $walletBalance = $subjectUser ? $this->walletLedger->balance($subjectUser) : null;

            if ($subjectUser && $walletBalance !== null && $walletBalance >= $locked->outstanding_cents) {
                $userAccount = $this->walletLedger->user($subjectUser);
                $platformAccount = $this->walletLedger->platform('commission');
                $charge = $locked->outstanding_cents;
                $tx = $this->walletLedger->post(
                    'penalty:'.$locked->reference,
                    'platform_penalty_charge',
                    $charge,
                    [$userAccount->id => -$charge, $platformAccount->id => $charge],
                    $actor,
                    "Audited penalty {$locked->reference}: {$locked->reason}",
                    ['penalty_id' => $locked->id, 'category' => $locked->category],
                    $locked->order_id
                );

                $locked->update([
                    'status' => 'approved_charged',
                    'settled_cents' => $locked->amount_cents,
                    'outstanding_cents' => 0,
                    'approved_by_id' => $actor->id,
                    'wallet_transaction_id' => $tx->id,
                    'decision_notes' => trim($data['decision_notes']),
                    'decided_at' => now(),
                    'settled_at' => now(),
                ]);
                if ($locked->scale_discrepancy_log_id) {
                    ScaleDiscrepancyLog::whereKey($locked->scale_discrepancy_log_id)->update(['status' => 'penalized']);
                }
                $enforcement->syncDebtSanctionClearance($locked->id, $locked->subject_user_id);
            } else {
                // Non-negative wallet rule (B04): hold payouts & catalog visibility until settled.
                $locked->update([
                    'status' => 'unpaid_debt_hold',
                    'approved_by_id' => $actor->id,
                    'decision_notes' => trim($data['decision_notes']),
                    'decided_at' => now(),
                ]);
                if ($locked->scale_discrepancy_log_id) {
                    ScaleDiscrepancyLog::whereKey($locked->scale_discrepancy_log_id)->update(['status' => 'penalized']);
                }
                $this->applyDebtRestrictions($actor, $locked, $enforcement);
            }

            if ($subjectUser) {
                app(NotificationDeliveryService::class)->record(
                    $subjectUser->id,
                    'financial.penalty.'.$locked->status,
                    'pen-decided:'.$locked->reference.':'.$locked->status,
                    [
                        'reference' => $locked->reference,
                        'amount_cents' => $locked->amount_cents,
                        'status' => $locked->status,
                    ]
                );
            }

            return $locked->fresh();
        });
    }

    public function settleDebtFromWallet(User $actor, FinancialPenalty $penalty, EnforcementService $enforcement): FinancialPenalty
    {
        return DB::transaction(function () use ($actor, $penalty, $enforcement) {
            $locked = FinancialPenalty::whereKey($penalty->id)->lockForUpdate()->firstOrFail();
            abort_unless(
                $actor->isAdmin() || $locked->subject_user_id === $actor->id,
                403,
                'Only the obligated account owner or a verified admin can settle this penalty.'
            );
            if ($locked->status !== 'unpaid_debt_hold' || $locked->outstanding_cents <= 0) {
                return $locked;
            }

            $subjectUser = User::whereKey($locked->subject_user_id)->lockForUpdate()->firstOrFail();
            $balance = $this->walletLedger->balance($subjectUser);
            if ($balance === null || $balance < $locked->outstanding_cents) {
                throw ValidationException::withMessages([
                    'wallet' => 'Insufficient Bagoo Wallet balance to clear this debt obligation without a negative balance.',
                ]);
            }

            $charge = $locked->outstanding_cents;
            $userAccount = $this->walletLedger->user($subjectUser);
            $platformAccount = $this->walletLedger->platform('commission');
            $tx = $this->walletLedger->post(
                'penalty-settle:'.$locked->reference,
                'platform_penalty_settlement',
                $charge,
                [$userAccount->id => -$charge, $platformAccount->id => $charge],
                $actor,
                "Settled debt obligation {$locked->reference}",
                ['penalty_id' => $locked->id],
                $locked->order_id
            );

            $locked->update([
                'status' => 'approved_charged',
                'settled_cents' => $locked->amount_cents,
                'outstanding_cents' => 0,
                'wallet_transaction_id' => $tx->id,
                'settled_at' => now(),
            ]);

            $enforcement->syncDebtSanctionClearance($locked->id, $locked->subject_user_id);

            return $locked->fresh();
        });
    }

    private function applyDebtRestrictions(User $admin, FinancialPenalty $penalty, EnforcementService $enforcement): void
    {
        $hasExistingPayoutHold = Schema::hasTable('enforcement_sanctions') && $penalty->subjectUser
            ? $enforcement->activePayoutHoldForUser($penalty->subjectUser) !== null
            : false;

        if (! $hasExistingPayoutHold && in_array($penalty->subject_type, ['seller', 'company', 'courier'], true)) {
            $enforcement->issueSanction($admin, [
                'subject_type' => $penalty->subject_type,
                'subject_id' => $penalty->subject_id,
                'scope' => 'payout_hold',
                'reason_code' => 'unpaid_debt_obligation',
                'reason' => "Payout hold while penalty {$penalty->reference} remains unpaid (PHP ".number_format($penalty->outstanding_cents / 100, 2).').',
                'evidence_summary' => $penalty->evidence_summary,
                'source_financial_penalty_id' => $penalty->id,
                'auto_release_on_debt_clear' => true,
            ], allowSystemIssuer: true);
        }

        if ($penalty->subject_type === 'seller' && $penalty->subject_user_id) {
            $shop = Shop::where('user_id', $penalty->subject_user_id)->first();
            $enforcement->issueSanction($admin, [
                'subject_type' => $shop ? 'shop' : 'seller',
                'subject_id' => $shop ? $shop->id : $penalty->subject_id,
                'scope' => 'catalog_visibility_restricted',
                'reason_code' => 'unpaid_debt_obligation',
                'reason' => "Public catalog listings paused while penalty {$penalty->reference} remains unpaid.",
                'evidence_summary' => $penalty->evidence_summary,
                'source_financial_penalty_id' => $penalty->id,
                'auto_release_on_debt_clear' => true,
            ], allowSystemIssuer: true);
        }
    }
}
