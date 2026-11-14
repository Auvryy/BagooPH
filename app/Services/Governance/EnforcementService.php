<?php

namespace App\Services\Governance;

use App\Models\EnforcementAppeal;
use App\Models\EnforcementSanction;
use App\Models\FinancialPenalty;
use App\Models\LogisticsCompany;
use App\Models\ModerationSignal;
use App\Models\Shop;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Services\Notifications\NotificationDeliveryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EnforcementService
{
    public const REASON_CODES = [
        'repeated_cod_refusal' => 'Repeated COD delivery refusal',
        'unpaid_debt_obligation' => 'Unpaid platform or recovery debt obligation',
        'verified_off_platform_bypass' => 'Verified off-platform transaction bypass',
        'review_policy_abuse' => 'Verified review manipulation or abuse',
        'pickup_sla_zone_failure' => 'Verified pickup zone SLA breach',
        'weight_overage_repeat' => 'Repeated declared parcel weight under-statement',
        'policy_warning' => 'Documented platform policy warning',
    ];

    public function assertVerifiedAdmin(User $actor): User
    {
        $user = User::findOrFail($actor->id);
        abort_unless(
            $user->isAdmin() && $user->canAccessPortal() && $user->email_verified_at !== null,
            403,
            'Verified active platform administrator authority is required.'
        );

        return $user;
    }

    /** @return array{subject_type: string, subject_id: int, subject_user_id: ?int, label: string} */
    public function resolveSubject(string $subjectType, int $subjectId): array
    {
        if (in_array($subjectType, ['buyer', 'seller', 'courier'], true)) {
            $user = User::findOrFail($subjectId);
            $roleMatches = $user->role === $subjectType || ($subjectType === 'courier' && $user->role === 'logistics');
            if (! $roleMatches) {
                throw ValidationException::withMessages(['subject_id' => 'Selected account role does not match the subject type.']);
            }

            return [
                'subject_type' => $subjectType,
                'subject_id' => $user->id,
                'subject_user_id' => $user->id,
                'label' => $user->name.' ('.$subjectType.')',
            ];
        }

        if ($subjectType === 'shop') {
            $shop = Shop::with('user')->findOrFail($subjectId);

            return [
                'subject_type' => 'shop',
                'subject_id' => $shop->id,
                'subject_user_id' => $shop->user_id,
                'label' => $shop->name.' (shop)',
            ];
        }

        if ($subjectType === 'company') {
            $company = LogisticsCompany::with('user')->findOrFail($subjectId);

            return [
                'subject_type' => 'company',
                'subject_id' => $company->id,
                'subject_user_id' => $company->user_id,
                'label' => $company->name.' (company)',
            ];
        }

        throw ValidationException::withMessages(['subject_type' => 'Unsupported enforcement subject type.']);
    }

    public function issueSanction(User $actor, array $input, bool $allowSystemIssuer = false): EnforcementSanction
    {
        if (! $allowSystemIssuer) {
            $actor = $this->assertVerifiedAdmin($actor);
        }

        $data = Validator::make($input, [
            'subject_type' => ['required', 'string', Rule::in(['buyer', 'seller', 'shop', 'company', 'courier'])],
            'subject_id' => ['required', 'integer', 'min:1'],
            'scope' => ['required', 'string', Rule::in(EnforcementSanction::SCOPES)],
            'reason_code' => ['required', 'string', 'max:64'],
            'reason' => ['required', 'string', new ApplicationText('notes', 5, 1000)],
            'evidence_summary' => ['nullable', 'string', new ApplicationText('notes', 5, 1000)],
            'evidence_refs' => ['nullable', 'array'],
            'source_financial_penalty_id' => ['nullable', 'integer', 'exists:financial_penalties,id'],
            'source_signal_id' => ['nullable', 'integer', 'exists:moderation_signals,id'],
            'auto_release_on_debt_clear' => ['sometimes', 'boolean'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ])->validate();

        $refs = $data['evidence_refs'] ?? [];
        $summary = trim((string) ($data['evidence_summary'] ?? ''));

        if ($data['scope'] !== 'warning' && $summary === '' && $refs === []
            && empty($data['source_financial_penalty_id']) && empty($data['source_signal_id'])) {
            throw ValidationException::withMessages([
                'evidence_summary' => 'Verified evidence or linked operational records are required before imposing a restriction.',
            ]);
        }

        if (! empty($data['source_signal_id']) && $summary === '') {
            $signal = ModerationSignal::find($data['source_signal_id']);
            if ($signal && ! $signal->is_punitive && $signal->signal_type === 'off_platform_bypass_keyword' && $signal->status === 'open') {
                throw ValidationException::withMessages([
                    'evidence_summary' => 'Keyword detection alone does not establish abuse. Corroborate the signal or document verified evidence first.',
                ]);
            }
        }

        if ($data['scope'] === 'pickup_zone_restricted' && empty($refs['hub_id']) && empty($refs['barangay']) && $summary === '') {
            throw ValidationException::withMessages([
                'evidence_refs' => 'Pickup zone restrictions must identify the affected hub or barangay and supporting SLA evidence.',
            ]);
        }

        $subject = $this->resolveSubject($data['subject_type'], (int) $data['subject_id']);
        if (! $allowSystemIssuer && $subject['subject_user_id'] === $actor->id) {
            throw ValidationException::withMessages(['subject_id' => 'You cannot issue an enforcement sanction against your own account.']);
        }

        return DB::transaction(function () use ($actor, $data, $subject, $refs, $summary, $allowSystemIssuer) {
            $sanction = EnforcementSanction::create([
                'reference' => 'ENF-'.Str::upper(Str::random(10)),
                'subject_type' => $subject['subject_type'],
                'subject_id' => $subject['subject_id'],
                'subject_user_id' => $subject['subject_user_id'],
                'scope' => $data['scope'],
                'status' => 'active',
                'reason_code' => $data['reason_code'],
                'reason' => trim($data['reason']),
                'evidence_summary' => $summary !== '' ? $summary : null,
                'evidence_refs' => $refs !== [] ? $refs : null,
                'source_financial_penalty_id' => $data['source_financial_penalty_id'] ?? null,
                'source_signal_id' => $data['source_signal_id'] ?? null,
                'auto_release_on_debt_clear' => (bool) ($data['auto_release_on_debt_clear'] ?? false),
                'issued_by_id' => $allowSystemIssuer ? null : $actor->id,
                'issued_by_role' => $allowSystemIssuer ? 'system' : $actor->role,
                'issued_at' => now(),
                'expires_at' => isset($data['expires_at']) ? $data['expires_at'] : null,
            ]);

            if ($sanction->subject_user_id) {
                app(NotificationDeliveryService::class)->record(
                    $sanction->subject_user_id,
                    'enforcement.sanction.'.$sanction->scope,
                    'enf-issued:'.$sanction->reference,
                    [
                        'reference' => $sanction->reference,
                        'scope' => $sanction->scope,
                        'reason' => $sanction->reason,
                        'can_appeal' => true,
                    ]
                );
            }

            return $sanction;
        });
    }

    public function resolveSanction(User $actor, EnforcementSanction $sanction, array $input): EnforcementSanction
    {
        $actor = $this->assertVerifiedAdmin($actor);
        $data = Validator::make($input, [
            'resolution_notes' => ['required', 'string', new ApplicationText('notes', 5, 1000)],
            'status' => ['nullable', 'string', Rule::in(['resolved', 'overturned', 'expired'])],
        ])->validate();

        return DB::transaction(function () use ($actor, $sanction, $data) {
            $locked = EnforcementSanction::whereKey($sanction->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'active') {
                return $locked;
            }

            $locked->update([
                'status' => $data['status'] ?? 'resolved',
                'resolved_by_id' => $actor->id,
                'resolved_at' => now(),
                'resolution_notes' => trim($data['resolution_notes']),
            ]);

            return $locked->fresh();
        });
    }

    public function submitAppeal(User $actor, EnforcementSanction $sanction, array $input): EnforcementAppeal
    {
        $data = Validator::make($input, [
            'reason' => ['required', 'string', new ApplicationText('notes', 5, 1000)],
            'evidence_notes' => ['nullable', 'string', new ApplicationText('notes', 5, 1000)],
            'evidence_refs' => ['nullable', 'array'],
        ])->validate();

        return DB::transaction(function () use ($actor, $sanction, $data) {
            $locked = EnforcementSanction::whereKey($sanction->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->actorOwnsSanction($actor, $locked), 403, 'This enforcement record belongs to another account.');

            if ($locked->status !== 'active') {
                throw ValidationException::withMessages(['reason' => 'Only active restrictions or warnings can be appealed.']);
            }

            $openExists = EnforcementAppeal::where('enforcement_sanction_id', $locked->id)
                ->whereIn('status', ['pending', 'under_review'])
                ->exists();
            if ($openExists) {
                throw ValidationException::withMessages(['reason' => 'An appeal is already open for this enforcement record.']);
            }

            return EnforcementAppeal::create([
                'reference' => 'APL-'.Str::upper(Str::random(10)),
                'enforcement_sanction_id' => $locked->id,
                'appellant_user_id' => $actor->id,
                'reason' => trim($data['reason']),
                'evidence_notes' => isset($data['evidence_notes']) ? trim((string) $data['evidence_notes']) : null,
                'evidence_refs' => $data['evidence_refs'] ?? null,
                'status' => 'pending',
            ]);
        });
    }

    public function decideAppeal(User $actor, EnforcementAppeal $appeal, array $input): EnforcementAppeal
    {
        $actor = $this->assertVerifiedAdmin($actor);
        $data = Validator::make($input, [
            'decision' => ['required', 'string', Rule::in(['overturned', 'upheld'])],
            'review_notes' => ['required', 'string', new ApplicationText('notes', 5, 1000)],
        ])->validate();

        return DB::transaction(function () use ($actor, $appeal, $data) {
            $lockedAppeal = EnforcementAppeal::whereKey($appeal->id)->lockForUpdate()->firstOrFail();
            $sanction = EnforcementSanction::whereKey($lockedAppeal->enforcement_sanction_id)->lockForUpdate()->firstOrFail();

            if (! in_array($lockedAppeal->status, ['pending', 'under_review'], true)) {
                throw ValidationException::withMessages(['decision' => 'This appeal has already been decided.']);
            }

            abort_if(
                $sanction->issued_by_id !== null && $sanction->issued_by_id === $actor->id,
                403,
                'An independent platform admin who did not issue the original sanction must decide this appeal.'
            );
            abort_if($lockedAppeal->appellant_user_id === $actor->id, 403, 'You cannot decide your own appeal.');

            $lockedAppeal->update([
                'status' => $data['decision'],
                'reviewed_by_id' => $actor->id,
                'review_notes' => trim($data['review_notes']),
                'reviewed_at' => now(),
            ]);

            if ($data['decision'] === 'overturned' && $sanction->status === 'active') {
                $sanction->update([
                    'status' => 'overturned',
                    'resolved_by_id' => $actor->id,
                    'resolved_at' => now(),
                    'resolution_notes' => 'Overturned via '.$lockedAppeal->reference.': '.trim($data['review_notes']),
                ]);
            }

            if ($sanction->subject_user_id) {
                app(NotificationDeliveryService::class)->record(
                    $sanction->subject_user_id,
                    'enforcement.appeal.'.$data['decision'],
                    'apl-decided:'.$lockedAppeal->reference,
                    [
                        'appeal_reference' => $lockedAppeal->reference,
                        'sanction_reference' => $sanction->reference,
                        'decision' => $data['decision'],
                    ]
                );
            }

            return $lockedAppeal->fresh(['sanction']);
        });
    }

    public function expireDueSanctions(): int
    {
        if (! Schema::hasTable('enforcement_sanctions')) {
            return 0;
        }

        return EnforcementSanction::where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update([
                'status' => 'expired',
                'resolved_at' => now(),
                'resolution_notes' => 'Automatic expiry of time-bound sanction.',
                'updated_at' => now(),
            ]);
    }

    public function syncDebtSanctionClearance(?int $financialPenaltyId = null, ?int $subjectUserId = null): int
    {
        if (! Schema::hasTable('enforcement_sanctions') || ! Schema::hasTable('financial_penalties')) {
            return 0;
        }

        $sanctions = EnforcementSanction::where('status', 'active')
            ->where('auto_release_on_debt_clear', true)
            ->when($financialPenaltyId !== null, fn (Builder $q) => $q->where('source_financial_penalty_id', $financialPenaltyId))
            ->when($subjectUserId !== null, fn (Builder $q) => $q->where('subject_user_id', $subjectUserId))
            ->get();

        $lifted = 0;
        foreach ($sanctions as $sanction) {
            if ($sanction->source_financial_penalty_id) {
                $stillUnpaid = FinancialPenalty::whereKey($sanction->source_financial_penalty_id)
                    ->whereIn('status', ['pending_review', 'unpaid_debt_hold'])
                    ->where('outstanding_cents', '>', 0)
                    ->exists();
                if ($stillUnpaid) {
                    continue;
                }
            }

            $sanction->update([
                'status' => 'resolved',
                'resolved_at' => now(),
                'resolution_notes' => 'Automatically lifted upon verified clearance of the underlying debt obligation.',
            ]);
            $lifted++;
        }

        return $lifted;
    }

    public function activeCodRestrictionForBuyer(?User $buyer): ?EnforcementSanction
    {
        if (! $buyer || ! Schema::hasTable('enforcement_sanctions')) {
            return null;
        }

        return EnforcementSanction::active()
            ->where('scope', 'cod_restricted')
            ->where(fn (Builder $q) => $q
                ->where('subject_user_id', $buyer->id)
                ->orWhere(fn (Builder $s) => $s->where('subject_type', 'buyer')->where('subject_id', $buyer->id)))
            ->latest('id')
            ->first();
    }

    public function activeReviewRestrictionForBuyer(?User $buyer): ?EnforcementSanction
    {
        if (! $buyer || ! Schema::hasTable('enforcement_sanctions')) {
            return null;
        }

        return EnforcementSanction::active()
            ->where('scope', 'review_restricted')
            ->where(fn (Builder $q) => $q
                ->where('subject_user_id', $buyer->id)
                ->orWhere(fn (Builder $s) => $s->where('subject_type', 'buyer')->where('subject_id', $buyer->id)))
            ->latest('id')
            ->first();
    }

    public function activePayoutHoldForUser(?User $user): ?EnforcementSanction
    {
        if (! $user || ! Schema::hasTable('enforcement_sanctions')) {
            return null;
        }

        return EnforcementSanction::active()
            ->where('scope', 'payout_hold')
            ->where('subject_user_id', $user->id)
            ->latest('id')
            ->first();
    }

    /** @return Collection<int, EnforcementSanction> */
    public function activeForUser(?User $user): Collection
    {
        if (! $user || ! Schema::hasTable('enforcement_sanctions')) {
            return collect();
        }

        $shopIds = $user->isSeller() ? Shop::where('user_id', $user->id)->pluck('id')->all() : [];
        $companyIds = $user->isLogistics() ? LogisticsCompany::where('user_id', $user->id)->pluck('id')->all() : [];

        return EnforcementSanction::active()
            ->with(['appeals' => fn ($q) => $q->latest('id')])
            ->where(fn (Builder $q) => $q
                ->where('subject_user_id', $user->id)
                ->when($shopIds !== [], fn (Builder $s) => $s->orWhere(fn (Builder $m) => $m->where('subject_type', 'shop')->whereIn('subject_id', $shopIds)))
                ->when($companyIds !== [], fn (Builder $c) => $c->orWhere(fn (Builder $m) => $m->where('subject_type', 'company')->whereIn('subject_id', $companyIds))))
            ->latest('id')
            ->get();
    }

    public function actorOwnsSanction(User $actor, EnforcementSanction $sanction): bool
    {
        if ($sanction->subject_user_id === $actor->id) {
            return true;
        }
        if ($sanction->subject_type === 'shop') {
            return Shop::whereKey($sanction->subject_id)->where('user_id', $actor->id)->exists();
        }
        if ($sanction->subject_type === 'company') {
            return LogisticsCompany::whereKey($sanction->subject_id)->where('user_id', $actor->id)->exists();
        }

        return in_array($sanction->subject_type, ['buyer', 'seller', 'courier'], true)
            && $sanction->subject_id === $actor->id;
    }
}
