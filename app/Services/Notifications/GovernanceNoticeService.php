<?php

namespace App\Services\Notifications;

use App\Models\AccountClosure;
use App\Models\CustodyRecoveryGrant;
use App\Models\CustodyRecoveryReceipt;
use App\Models\ExceptionDecision;
use App\Models\HubHandler;
use App\Models\IdentityCorrectionDecision;
use App\Models\IdentityCorrectionRequest;
use App\Models\KycDecision;
use App\Models\LogisticsPlacementRecord;
use App\Models\ProductModerationDecision;
use App\Models\RestrictionDecision;
use App\Models\ShopReviewDecision;
use App\Models\User;
use App\Services\ExceptionOversightService;
use Illuminate\Database\Eloquent\Model;

class GovernanceNoticeService
{
    public function __construct(private readonly NotificationDeliveryService $delivery) {}

    public function record(Model $event): void
    {
        if ($event instanceof KycDecision) {
            $this->send($event, 'kyc_review', [$event->user_id => 'account-status'],
                $event->decision === 'approved' ? 'Your application review was approved' : 'Your application needs a correction',
                'Open your account status for the review outcome and permitted next steps. Activity and resource eligibility still apply.');
        } elseif ($event instanceof ShopReviewDecision) {
            // Original-shop and correction decisions already have their own account event.
            if (! $event->kyc_decision_id && ! $event->identity_correction_request_id) {
                $this->send($event, 'shop_review', [$event->seller_id => 'account-status'],
                    $event->decision === 'approved' ? 'Your shop review was approved' : 'Your shop needs a review correction',
                    'Check your own shop status and review feedback. Approval does not clear an independent restriction.');
            }
        } elseif ($event instanceof RestrictionDecision) {
            $recipients = $event->subject_type === 'account' ? [$event->subject_id] : array_filter([
                $event->before_state['owner']['id'] ?? null, $event->before_state['member']['id'] ?? null,
            ]);
            $action = match ($event->action) {
                'suspend' => 'suspended', 'deactivate' => 'deactivated', 'reactivate' => 'reactivated'
            };
            $subject = match ($event->subject_type) {
                'account' => 'account', 'shop' => 'shop', 'company' => 'company', 'hub' => 'facility',
                'handler' => 'handler assignment', 'fleet' => 'vehicle',
            };
            $this->send($event, 'restriction', array_fill_keys(array_unique($recipients), 'account-status'),
                'Your '.$subject.' was '.$action,
                'Existing order, parcel, and cash responsibilities remain recorded. Approval and resource eligibility are checked separately.');
        } elseif ($event instanceof IdentityCorrectionDecision) {
            $request = IdentityCorrectionRequest::findOrFail($event->correction_request_id);
            $this->send($event, 'identity_correction', [$request->user_id => 'identity-correction'],
                $event->action === 'approve' ? 'Your identity correction was approved' : 'Your identity correction was rejected',
                'Open your own correction history to see the reviewed result. Existing activity restrictions remain in effect.');
        } elseif ($event instanceof ProductModerationDecision) {
            $sellerId = $event->before_state['seller']['id'] ?? null;
            if ($sellerId) {
                $this->send($event, 'product_moderation', [$sellerId => 'seller-products'],
                    $event->action === 'remove' ? 'A product received a compliance restriction' : 'A product compliance restriction was lifted',
                    'Check your product list for its current compliance status. Shop and account eligibility still apply.');
            }
        } elseif ($event instanceof LogisticsPlacementRecord) {
            $this->send($event, 'placement', [$event->user_id => 'account-status'],
                'Your logistics assignment was recorded', 'Check your current assignment before taking work. Rider duty remains a separate choice.');
        } elseif ($event instanceof ExceptionDecision) {
            $kind = null;
            $id = null;
            foreach (ExceptionOversightService::SOURCES as $candidate => [, $field]) {
                if ($event->$field) {
                    $kind = $candidate;
                    $id = $event->$field;
                    break;
                }
            }
            $recipients = [$event->actor_id => 'exception'];
            if ($event->responsible_user_id && $event->responsible_user_id !== $event->actor_id) {
                $responsible = User::find($event->responsible_user_id);
                $recipients[$event->responsible_user_id] = $responsible?->isLogistics() ? 'custody-receiving' : 'account-status';
            }
            $this->send($event, 'exception', $recipients,
                $event->action === 'assign' ? 'A recovery responsibility was assigned' : 'A recovery review was resolved',
                'The review result was recorded against its source evidence. Physical custody and cash records remain separate.',
                ['exception_kind' => $kind, 'exception_id' => $id]);
        } elseif ($event instanceof CustodyRecoveryGrant) {
            $recipients = [$event->courier_id => 'custody-own', $event->authorized_by_id => 'custody-work'];
            foreach (HubHandler::eligible()->where('hub_id', $event->hub_id)->where('logistics_company_id', $event->logistics_company_id)->pluck('user_id') as $id) {
                $recipients[$id] = 'custody-receiving';
            }
            $this->send($event, 'custody_grant', $recipients, 'A restricted parcel handover was authorized',
                'Use the owned recovery workflow to acknowledge or receive the original parcel. The rider restriction stays in effect.',
                ['work_id' => $event->restriction_affected_work_id, 'grant_id' => $event->id]);
        } elseif ($event instanceof CustodyRecoveryReceipt) {
            $grant = CustodyRecoveryGrant::findOrFail($event->custody_recovery_grant_id);
            $this->send($event, 'custody_receipt', [$grant->courier_id => 'custody-own', $grant->authorized_by_id => 'custody-work'],
                $event->event_type === 'received' ? 'The receiving hub recorded the parcel handover' : 'The rider acknowledged the parcel handover',
                'This update records the actual recovery step. The account restriction and financial obligations are unchanged.',
                ['work_id' => $grant->restriction_affected_work_id, 'grant_id' => $grant->id]);
        } elseif ($event instanceof AccountClosure) {
            // Closure may hard-delete an unreferenced identity; never add a reference before that decision.
            $recipients = [];
            if (User::whereKey($event->actor_id)->exists()) {
                $recipients[$event->actor_id] = 'governance-history';
            }
            if ($event->outcome === 'retained' && User::whereKey($event->subject_id)->exists()) {
                $recipients[$event->subject_id] = null;
            }
            $this->send($event, 'account_closure', $recipients, 'The account closure was recorded',
                'The closure decision preserves its audit evidence. Closed accounts cannot start new work or reopen from a notice.');
        }
    }

    private function send(Model $event, string $kind, array $recipients, string $title, string $body, array $context = []): void
    {
        foreach ($recipients as $id => $target) {
            if (! $id || ! User::whereKey($id)->exists()) {
                continue;
            }
            $this->delivery->record((int) $id, 'governance-event', 'governance:'.$kind.':'.$event->id,
                ['title' => $title, 'body' => $body, 'target' => $target,
                    'governance_source' => $kind, 'governance_id' => $event->id] + $context);
        }
    }
}
