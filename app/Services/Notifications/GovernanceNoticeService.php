<?php

namespace App\Services\Notifications;

use App\Models\AccountClosure;
use App\Models\AdvertisementReview;
use App\Models\AdvertisementSubmission;
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
use App\Models\VoucherCampaignAction;
use App\Services\ExceptionOversightService;
use Illuminate\Database\Eloquent\Model;

class GovernanceNoticeService
{
    public function __construct(private readonly NotificationDeliveryService $delivery) {}

    public function record(Model $event): void
    {
        if ($event instanceof AdvertisementSubmission || $event instanceof AdvertisementReview) {
            $submission = $event instanceof AdvertisementSubmission ? $event : $event->submission;
            $ad = $submission->advertisement;
            $adminMessage = $event instanceof AdvertisementSubmission || $event->actor_role === 'seller';
            $recipients = $adminMessage
                ? User::where('role', 'admin')->whereNotNull('email_verified_at')->get()->filter(fn ($user) => $user->canAccessPortal())->mapWithKeys(fn ($user) => [$user->id => 'promotion-admin'])->all()
                : [$ad->created_by_id => 'promotion-seller'];
            $this->send($event, $event instanceof AdvertisementSubmission ? 'advertisement_submission' : 'advertisement_review', $recipients,
                $event instanceof AdvertisementSubmission ? 'A seller promotion is waiting for review' : 'A promotion review was updated',
                'Open the promotion to see its submitted version, review feedback and current display state.',
                ['advertisement_id' => $ad->id, 'submission_id' => $submission->id]);
        } elseif ($event instanceof VoucherCampaignAction && in_array($event->action, ['suspend', 'reinstate'], true)) {
            $sellerId = $event->voucher?->shop?->user_id;
            if ($sellerId) {
                $this->send($event, 'voucher_campaign', [$sellerId => 'seller-vouchers'],
                    $event->action === 'suspend' ? 'Your voucher campaign was suspended' : 'Your voucher campaign suspension was cleared',
                    'Campaign '.$event->voucher->code.': '.$event->reason.'. Placed orders keep their original savings.');
            }
        } elseif ($event instanceof KycDecision) {
            $this->send($event, 'kyc_review', [$event->user_id => 'account-status'],
                $event->decision === 'approved' ? 'Your application was approved' : 'Your application was rejected',
                $event->decision === 'approved'
                    ? 'Your application was approved. Sign in to see your current account status. Any separate account or shop/company restriction still applies.'
                    : 'Reason: '.$event->reason."\nSign in to correct your application or request a review.");
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
                ($event->owner_feedback ? 'Reason: '.$event->owner_feedback."\n" : '').'Existing orders, parcels and cash responsibilities stay recorded. '.
                (in_array($event->subject_type, ['account', 'shop', 'company'], true)
                    ? 'Sign in to see your status and request a review if needed.'
                    : 'Contact your company administrator about this change.'));
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
            $this->send($event, 'placement', [$event->user_id => $event->kind === 'courier' ? 'courier-profile' : 'account-status'],
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
            $delivery = $this->delivery->record((int) $id, 'governance-event', 'governance:'.$kind.':'.$event->id,
                ['title' => $title, 'body' => $body, 'target' => $target,
                    'governance_source' => $kind, 'governance_id' => $event->id] + $context);
            if ($event instanceof KycDecision || $event instanceof ShopReviewDecision
                || ($event instanceof RestrictionDecision && in_array($event->subject_type, ['account', 'shop', 'company'], true))) {
                app(AccountStatusDeliveryService::class)->requireEmail($delivery);
            }
        }
    }
}
