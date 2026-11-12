<?php

namespace App\Services\Returns;

use App\Models\BuyerReturnAdjustment;
use App\Models\BuyerReturnCase;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ReturnFaultReviewService
{
    public function review(User $actor, BuyerReturnCase $case, array $input): array
    {
        $policy = $case->order->shipping_quote['refund_policy_version'] ?? 1;
        abort_unless(in_array($policy, [1, 2], true), 409, 'The original refund policy needs its supported review workflow.');
        $fault = $input['fault'] ?? null;
        if (! $fault) {
            if ($policy !== 1) {
                throw ValidationException::withMessages(['fault' => 'Choose the evidenced seller or courier fault before approving this return.']);
            }

            return ['policy_version' => 1, 'fault' => 'unspecified', 'company_id' => null, 'evidence' => null];
        }
        abort_unless(in_array($fault, ['seller', 'courier'], true), 422);
        abort_if(BuyerReturnAdjustment::where('buyer_return_case_id', $case->id)->exists(), 409,
            'The repayment basis is already approved. Its original fault and amount cannot be replaced.');
        if ($fault === 'courier') {
            abort_unless($actor->isAdmin() && $actor->canAccessPortal() && $actor->email_verified_at
                && ! in_array($actor->id, [$case->buyer_id, $case->seller_id], true), 403,
                'Courier fault requires an independent platform review; the seller cannot assign courier liability.');
            if (! trim((string) ($input['fault_evidence'] ?? ''))) {
                throw ValidationException::withMessages(['fault_evidence' => 'Identify the actual transit or handover evidence supporting this finding.']);
            }
        }

        return ['policy_version' => $policy, 'fault' => $fault,
            'company_id' => $fault === 'courier' ? ($case->route_snapshot['forward_route']['logistics_company_id'] ?? null) : null,
            'evidence' => $input['fault_evidence'] ?? null, 'delivery_checkpoint_id' => $case->delivery_checkpoint_id,
            'reviewed_evidence_ids' => $case->evidence()->orderBy('id')->pluck('id')->all()];
    }

    public function current(BuyerReturnCase $case): ?array
    {
        $event = $case->events()->reorder()->whereIn('event_type', ['approve', 'review-fault'])
            ->orderByDesc('sequence')->first();
        $review = $event?->context['fault_review'] ?? null;

        return is_array($review) ? $review + ['event_reference' => $event->reference, 'actor_role' => $event->actor_role,
            'reason' => $event->reason, 'decided_at' => $event->created_at->toIso8601String()] : null;
    }
}
