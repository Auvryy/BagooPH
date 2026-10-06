<?php

namespace App\Services;

use App\Models\AccountClosure;
use App\Models\IdentityCorrectionDecision;
use App\Models\IdentityCorrectionRequest;
use App\Models\KycDecision;
use App\Models\Shop;
use App\Models\User;

class AccountContextService
{
    public function __construct(private readonly AccountRestrictionService $restrictions, private readonly GovernanceHistoryService $history) {}

    public function presentation(User $actor, int $id): array
    {
        $this->restrictions->currentActor($actor);
        $user = User::find($id);
        $closure = AccountClosure::where('subject_id', $id)->first();
        abort_unless($user || $closure, 404);
        $historyUrl = '/governance-history?subject_type=account&subject_id='.$id;
        if (! $user) {
            return ['account' => ['id' => $id, 'name' => $closure->subject_name, 'role' => $closure->subject_role,
                'status' => null, 'kyc_status' => null, 'closed_at' => $closure->closed_at->toISOString()],
                'record_state' => 'deleted_after_safe_closure', 'approval_provenance' => 'current_account_unavailable',
                'identity_provenance' => 'retained_closure_only', 'scope' => null, 'shops' => [],
                'work_count' => 0, 'work' => [], 'history_url' => $historyUrl,
                'closure_url' => '/governance-history/account_closure/'.$closure->id];
        }
        $state = $this->restrictions->state($user);
        $review = KycDecision::where('user_id', $id)->orderByDesc('id')->first();
        $correction = IdentityCorrectionDecision::where('action', 'approve')->whereIn('correction_request_id',
            IdentityCorrectionRequest::where('user_id', $id)->where('version', $user->identity_version)->select('id'))->exists();

        return [
            'account' => [...$user->only(['id', 'name', 'email', 'phone', 'role', 'status', 'kyc_status', 'kyc_reviewed_at', 'closed_at', 'identity_version']),
                'birthday' => $user->birthday?->format('Y-m-d')],
            'record_state' => $user->closed_at ? 'closed_and_retained' : 'current',
            'approval_provenance' => $user->isAdmin() ? 'controlled_admin_access' : ($review ? 'recorded_application_review' : 'legacy_without_recorded_review'),
            'identity_provenance' => $correction ? 'recorded_reviewed_correction' : ($review ? 'recorded_application_review' : 'legacy_without_recorded_review'),
            'scope' => $this->history->safeSnapshot(['subject' => $state['subject'], 'companies' => $state['companies'], 'handlers' => $state['handlers'], 'courier' => $state['courier']]),
            'shops' => Shop::where('user_id', $id)->orderBy('id')->get()->map(fn ($shop) => [
                ...$shop->only(['id', 'name', 'status', 'review_status', 'root_category_id']),
                'eligible' => app(ShopEligibilityService::class)->isEligible($shop),
                'history_url' => '/governance-history?subject_type=shop&subject_id='.$shop->id,
            ])->all(),
            'work_count' => count($state['work']), 'work' => $this->history->safeSnapshot(array_slice($state['work'], 0, 10)),
            'history_url' => $historyUrl, 'closure_url' => $closure ? '/governance-history/account_closure/'.$closure->id : null,
        ];
    }
}
