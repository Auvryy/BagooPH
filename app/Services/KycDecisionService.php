<?php

namespace App\Services;

use App\Models\CourierProfile;
use App\Models\KycDecision;
use App\Models\LogisticsCompany;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class KycDecisionService
{
    public function __construct(private VerificationDocumentService $documents) {}

    public function submission(User $user, bool $lockCategory = false): array
    {
        $user->loadMissing(['shop' => fn ($query) => $query->orderBy('id'), 'courierProfile', 'logisticsCompany']);

        return [
            'submitted_at' => $user->kyc_submitted_at?->toISOString(),
            'role' => $user->role,
            'account' => $user->only(['name', 'email', 'phone', 'address', 'city', 'birthday']),
            'shop' => $user->shop?->only(['id', 'name', 'phone', 'address', 'city', 'root_category_id']),
            'shop_category' => $user->isSeller() ? app(MasterCategoryService::class)->snapshot($user->shop?->root_category_id, $lockCategory) : null,
            'courier' => $user->courierProfile?->only(['id', 'vehicle_type', 'plate_number', 'license_number']),
            'company' => $user->logisticsCompany?->only(['id', 'name', 'contact_email', 'contact_phone', 'address']),
            'franchise_number' => $user->logisticsCompany?->accreditation_details['franchise_number'] ?? null,
            'documents' => $this->documents->evidence($user),
        ];
    }

    public function token(array $submission): string
    {
        return hash_hmac('sha256', json_encode($submission, JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function requiredDocuments(array $submission): array
    {
        $required = match ($submission['role']) {
            'buyer' => ['id'],
            'seller' => ['id', 'permit'],
            'courier' => ['id', 'license', 'orcr'],
            'logistics' => ['permit'],
            default => [],
        };
        if ($submission['role'] === 'logistics' && (
            ! empty($submission['documents']['franchise']['path'])
            || (! empty($submission['franchise_number']) && $submission['franchise_number'] !== 'PENDING-LTFRB')
        )) {
            $required[] = 'franchise';
        }

        return $required;
    }

    public function readiness(array $submission): array
    {
        $issues = [];
        $birthDates = app(BirthDateEligibility::class);
        $adult = $birthDates->requiresAdult($submission['role']);
        if (($adult || $submission['account']['birthday'] !== null) && ($issue = $birthDates->issue($submission['account']['birthday'], $adult))) {
            $issues[] = $issue;
        }
        foreach ($this->requiredDocuments($submission) as $kind) {
            if (! $submission['documents'][$kind]['valid']) {
                $issues[] = "A valid private {$kind} document is required.";
            }
        }
        $profile = match ($submission['role']) {
            'seller' => 'shop', 'courier' => 'courier', 'logistics' => 'company', default => null,
        };
        if ($profile && ! $submission[$profile]) {
            $issues[] = 'The original application profile is missing.';
        }
        if ($submission['role'] === 'seller' && $submission['shop'] && ! ($submission['shop_category']['eligible'] ?? false)) {
            $issues[] = MasterCategoryService::ISSUE;
        }
        if ($submission['role'] === 'courier' && $submission['courier'] && (
            empty($submission['courier']['vehicle_type']) || empty($submission['courier']['plate_number'])
        )) {
            $issues[] = 'Vehicle type and plate number are required.';
        }

        return $issues;
    }

    public function presentation(User $user): array
    {
        $submission = $this->submission($user);

        return [
            'review_token' => $this->token($submission),
            'review_age' => app(BirthDateEligibility::class)->age($user->birthday),
            'required_documents' => $this->requiredDocuments($submission),
            'review_issues' => $this->readiness($submission),
            'shop_category' => $submission['shop_category'],
            'decision_history' => KycDecision::where('user_id', $user->id)->orderByDesc('id')->get()->map(fn (KycDecision $decision) => [
                'id' => $decision->id,
                'decision' => $decision->decision,
                'reason' => $decision->reason,
                'reviewer' => $decision->reviewer_name,
                'reviewed_at' => $decision->reviewed_at->toISOString(),
                'account_status' => $decision->after_state['account']['status'],
                'documents' => $this->documents->links($user, $decision),
            ])->all(),
        ];
    }

    public function recordInspection(Request $request, User $user, string $document): void
    {
        if (! $request->user()->isAdmin() || $request->user()->status !== 'active' || ! $user->isKycPending()) {
            return;
        }
        $submission = $this->submission($user);
        $kind = pathinfo($document, PATHINFO_FILENAME);
        if (! ($submission['documents'][$kind]['valid'] ?? false)) {
            return;
        }
        $key = 'kyc_inspection.'.$user->id;
        $inspection = $request->session()->get($key, []);
        $token = $this->token($submission);
        if (($inspection['token'] ?? null) !== $token || ($inspection['actor'] ?? null) !== $request->user()->id) {
            $inspection = ['token' => $token, 'actor' => $request->user()->id, 'documents' => []];
        }
        $inspection['documents'][$kind] = $submission['documents'][$kind]['sha256'];
        $request->session()->put($key, $inspection);
    }

    public function decide(Request $request, User $subject, string $action, array $validated): KycDecision
    {
        abort_unless(in_array($action, ['approved', 'rejected'], true), 400);

        return DB::transaction(function () use ($request, $subject, $action, $validated) {
            // Lock users by ID, then the original profile and selected category; submissions use the same order.
            $users = User::whereIn('id', [$request->user()->id, $subject->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = $users->get($request->user()->id);
            $user = $users->get($subject->id);
            abort_unless($actor?->isAdmin() && $actor->canAccessPortal(), 403);
            abort_unless($user && in_array($user->role, ['buyer', 'seller', 'courier', 'logistics'], true), 403);
            $this->lockProfile($user);
            $submission = $this->submission($user, lockCategory: true);
            $token = $this->token($submission);
            $reason = $action === 'rejected' ? trim($validated['reason']) : null;
            $existing = KycDecision::where('user_id', $user->id)->where('submission_token', $validated['review_token'])->first();
            if ($existing) {
                // Taxonomy activity may change after review; an identical retry cannot reactivate a shop.
                $currentApplication = $submission;
                $reviewedApplication = $existing->submission;
                unset($currentApplication['shop_category'], $reviewedApplication['shop_category']);
                abort_unless(hash_equals($this->token($currentApplication), $this->token($reviewedApplication)), 409, 'This application changed. Reload the queue and review its current evidence.');
                abort_unless($existing->decision === $action && $existing->reason === $reason && $existing->reviewer_id === $actor->id, 409, 'This submission has already been reviewed. Reload the queue.');

                return $existing;
            }
            abort_unless(hash_equals($token, $validated['review_token']), 409, 'This application changed. Reload the queue and review its current evidence.');
            abort_unless($user->isKycPending(), 409, 'Only a current pending application can be reviewed.');
            if ($action === 'approved') {
                $issues = $this->readiness($submission);
                if ($issues) {
                    throw ValidationException::withMessages(['review' => implode(' ', $issues)]);
                }
                $inspection = $request->session()->get('kyc_inspection.'.$user->id, []);
                foreach ($this->requiredDocuments($submission) as $kind) {
                    if (($inspection['token'] ?? null) !== $token || ($inspection['actor'] ?? null) !== $actor->id || ($inspection['documents'][$kind] ?? null) !== $submission['documents'][$kind]['sha256']) {
                        throw ValidationException::withMessages(['review' => 'Open and inspect every required document in this session before approving.']);
                    }
                }
            }
            $before = $this->state($user);
            $reviewedAt = now();
            $status = $user->status;
            if ($action === 'approved' && $status === 'pending_approval') {
                $status = 'active';
            } elseif ($action === 'rejected' && in_array($status, ['active', 'pending_approval'], true)) {
                $status = 'pending_approval';
            }
            $updates = ['kyc_status' => $action, 'status' => $status, 'kyc_reviewed_at' => $reviewedAt, 'kyc_feedback' => $reason];
            if ($action === 'approved' && app(BirthDateEligibility::class)->requiresAdult($user->role)) {
                $updates['age'] = app(BirthDateEligibility::class)->age($user->birthday);
            }
            $user->update($updates);
            if ($action === 'approved' && $status === 'active') {
                if ($user->isSeller() && $user->shop?->status === 'pending') {
                    $user->shop->update(['status' => 'active']);
                }
                if ($user->isLogistics() && $user->logisticsCompany?->status === 'pending') {
                    $user->logisticsCompany->update(['status' => 'active', 'is_active' => true]);
                }
            }
            if ($action === 'approved' && $user->isCourier()) {
                $user->courierProfile->update(['or_cr_status' => 'Verified & Registered']);
            }

            return KycDecision::create([
                'user_id' => $user->id, 'reviewer_id' => $actor->id,
                'reviewer_role' => $actor->role, 'reviewer_name' => $actor->name,
                'subject_role' => $user->role, 'submission_token' => $token,
                'decision' => $action, 'reason' => $reason, 'submission' => $submission,
                'before_state' => $before, 'after_state' => $this->state($user), 'reviewed_at' => $reviewedAt,
            ]);
        }, 3);
    }

    public function lockProfile(User $user): void
    {
        // Additional shops, fleet, assignments, facilities, and rider duty are never activated by KYC.
        $user->setRelation('shop', $user->isSeller() ? Shop::where('user_id', $user->id)->orderBy('id')->lockForUpdate()->first() : null);
        $user->setRelation('courierProfile', $user->isCourier() ? CourierProfile::where('user_id', $user->id)->lockForUpdate()->first() : null);
        $user->setRelation('logisticsCompany', $user->isLogistics() ? LogisticsCompany::where('user_id', $user->id)->lockForUpdate()->first() : null);
    }

    private function state(User $user): array
    {
        return [
            'account' => $user->only(['id', 'role', 'status', 'kyc_status', 'kyc_feedback', 'kyc_reviewed_at', 'birthday', 'age']),
            'shop' => $user->shop?->only(['id', 'status']),
            'courier' => $user->courierProfile?->only(['id', 'or_cr_status', 'is_available', 'logistics_company_id', 'assigned_hub_id', 'vehicle_id']),
            'company' => $user->logisticsCompany?->only(['id', 'status', 'is_active']),
        ];
    }
}
