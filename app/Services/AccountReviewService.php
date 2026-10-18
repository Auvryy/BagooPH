<?php

namespace App\Services;

use App\Models\AccountReviewDecision;
use App\Models\AccountReviewRequest;
use App\Models\KycDecision;
use App\Models\LogisticsCompany;
use App\Models\NotificationDelivery;
use App\Models\RestrictionDecision;
use App\Models\Shop;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Rules\WholeNumber;
use App\Services\Notifications\AccountStatusDeliveryService;
use App\Services\Notifications\NotificationDeliveryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountReviewService
{
    public function __construct(private readonly AccountRestrictionService $restrictions) {}

    private function owner(User $user): User
    {
        $user = User::findOrFail($user->id);
        abort_unless($user->closed_at === null, 403, 'This account is closed.');

        return $user;
    }

    public function heading(User $user): array
    {
        return match (true) {
            $user->status === 'suspended' => ['title' => 'Your account is suspended', 'message' => 'You can request a review below. New purchases and portal work are unavailable until an admin restores access.'],
            $user->status === 'inactive' => ['title' => 'Your account is deactivated', 'message' => 'You can request a review below. An admin must reactivate your account before you can place orders or use your work portal.'],
            $user->kyc_status === 'rejected' => ['title' => 'Your application was rejected', 'message' => 'Read the feedback below. You can correct your application or request another review.'],
            $user->canAccessPortal() => ['title' => 'Your account is approved', 'message' => 'Your account is approved. Any separate shop or company restriction is shown below.'],
            default => ['title' => 'Your application is under review', 'message' => 'Your application has not been approved yet. You can check its details and any requested corrections.'],
        };
    }

    public function sources(User $user): array
    {
        $sources = [];
        if (in_array($user->status, ['suspended', 'inactive'], true) || $user->kyc_status === 'rejected') {
            $sources[] = $this->source($user, 'account', $user->id);
        }
        foreach (['shop' => Shop::class, 'company' => LogisticsCompany::class] as $type => $model) {
            if (($type === 'shop' && ! $user->isSeller()) || ($type === 'company' && ! $user->isLogistics())) {
                continue;
            }
            $query = $model::where('user_id', $user->id)->where(function ($query) use ($type): void {
                $query->whereIn('status', ['suspended', 'inactive']);
                if ($type === 'company') {
                    $query->orWhere(fn ($query) => $query->where('status', 'active')->where('is_active', false));
                }
            });
            foreach ($query->orderBy('id')->get() as $resource) {
                $sources[] = $this->source($user, $type, $resource->id);
            }
        }

        return array_values(array_filter($sources));
    }

    private function source(User $user, string $type, int $id): ?array
    {
        $resource = match ($type) {
            'account' => $id === $user->id ? $user : null,
            'shop' => $user->isSeller() ? Shop::where('user_id', $user->id)->find($id) : null,
            'company' => $user->isLogistics() ? LogisticsCompany::where('user_id', $user->id)->find($id) : null,
            default => null,
        };
        $status = $resource && $type === 'company' ? app(ResourceRestrictionService::class)->status('company', $resource) : $resource?->status;
        if (! $resource || (! in_array($status, ['suspended', 'inactive'], true) && ! ($type === 'account' && $user->kyc_status === 'rejected'))) {
            return null;
        }
        $restricted = in_array($status, ['suspended', 'inactive'], true);
        $decision = RestrictionDecision::where('subject_type', $type)->where('subject_id', $id)->latest('id')->first();
        if (($decision?->after_state['subject']['restriction_version'] ?? null) !== $resource->restriction_version) {
            $decision = null;
        }
        $snapshot = [
            'owner' => $user->only(['id', 'role', 'status', 'kyc_status', 'kyc_reviewed_at', 'restriction_version', 'identity_version', 'closed_at']),
            'subject' => $resource->only($type === 'account' ? ['id', 'status', 'restriction_version'] : ['id', 'user_id', 'status', 'is_active', 'restriction_version']),
            'application_decision_id' => KycDecision::where('user_id', $user->id)->latest('id')->value('id'),
            'restriction_decision_id' => $decision?->id,
        ];

        return ['type' => $type, 'id' => $id, 'name' => $type === 'account' ? 'Your '.AccountIdentityService::label($user->role).' account' : $resource->name,
            'kind' => $restricted ? 'restriction' : 'rejection', 'status' => $restricted ? $status : 'rejected',
            'reason' => $restricted ? ($decision?->owner_feedback ?? 'The admin has not provided feedback for you about this restriction. You can request a review below.') : ($user->kyc_feedback ?: 'Your application needs another review.'),
            'source_token' => $this->restrictions->token($snapshot), 'snapshot' => $snapshot];
    }

    public function needsAttention(User $user): bool
    {
        return $this->sources($user) !== [];
    }

    public function presentation(User $user): array
    {
        $user = $this->owner($user);
        $sources = $this->sources($user);
        $requests = AccountReviewRequest::where('user_id', $user->id)->with('decision.reviewer')->latest('id')->limit(20)->get();
        $email = NotificationDelivery::where('recipient_id', $user->id)->where('email_required', true)->latest('created_at')->latest('id')->first();

        return [...$this->heading($user), 'account' => $user->only(['id', 'name', 'email', 'role', 'status', 'kyc_status', 'email_verified_at']),
            'sources' => array_map(function ($source) use ($requests): array {
                $source['pending_request_id'] = $requests->first(fn ($item) => $item->status === 'pending'
                    && $item->subject_type === $source['type'] && $item->subject_id === $source['id'] && hash_equals($item->source_token, $source['source_token']))?->id;
                unset($source['snapshot']);

                return $source;
            }, $sources),
            'requests' => $requests->map(fn ($item) => $this->requestData($item))->all(),
            'can_correct_application' => in_array($user->kyc_status, ['none', 'rejected', 'pending_approval'], true) && ! $user->isAdmin(),
            'can_correct_identity' => $user->isKycApproved(), 'can_open_portal' => $user->canAccessPortal() && $sources === [],
            'email_update' => $email ? ['pending' => $email->email_delivered_at === null, 'attempts' => $email->email_attempts] : null,
        ];
    }

    private function requestData(AccountReviewRequest $item): array
    {
        return ['id' => $item->id, 'subject_type' => $item->subject_type, 'subject_id' => $item->subject_id,
            'message' => $item->message, 'status' => $item->status, 'created_at' => $item->created_at->toISOString(),
            'resolved_at' => $item->resolved_at?->toISOString(),
            'decision' => $item->decision ? ['action' => $item->decision->action, 'reason' => $item->decision->reason,
                'reviewer' => $item->decision->reviewer?->name, 'reviewed_at' => $item->decision->reviewed_at->toISOString()] : null];
    }

    public function submit(Request $request): AccountReviewRequest
    {
        if (is_string($request->input('message'))) {
            $request->merge(['message' => trim($request->input('message'), ' ')]);
        }
        $data = $request->validate([
            'subject_type' => 'required|string|in:account,shop,company', 'subject_id' => ['bail', 'required', new WholeNumber(PHP_INT_MAX), 'integer', 'min:1'],
            'source_token' => 'required|string|regex:/\A[a-f0-9]{64}\z/',
            'message' => ['bail', 'required', 'string', new ApplicationText('notes', 20, 2000)],
            'user_id' => 'prohibited', 'status' => 'prohibited', 'reviewer_id' => 'prohibited', 'role' => 'prohibited',
        ]);

        return DB::transaction(function () use ($request, $data): AccountReviewRequest {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $this->owner($user);
            $source = $this->source($user, $data['subject_type'], (int) $data['subject_id']);
            abort_unless($source, 403, 'You can request a review only for your own rejected application or restricted account, shop or company.');
            abort_unless(hash_equals($source['source_token'], $data['source_token']), 409, 'Your status changed. Reload this page before requesting a review.');
            $message = $data['message'];
            $pending = AccountReviewRequest::where('user_id', $user->id)->where('subject_type', $data['subject_type'])
                ->where('subject_id', $data['subject_id'])->where('status', 'pending')->first();
            if ($pending && hash_equals($pending->source_token, $source['source_token'])) {
                if ($pending->message === $message) {
                    return $pending;
                }
                throw ValidationException::withMessages(['message' => 'You already have a request waiting for review. Please wait for the admin response.']);
            }
            if ($pending) {
                $pending->forceFill(['status' => 'superseded', 'resolved_at' => now()])->save();
            }
            $recent = AccountReviewRequest::where('user_id', $user->id)->where('source_token', $source['source_token'])
                ->where('status', 'declined')->where('resolved_at', '>', now()->subDay())->exists();
            if ($recent) {
                throw ValidationException::withMessages(['message' => 'Your last request was reviewed recently. You can send another request tomorrow, or correct the application details if needed.']);
            }
            $result = AccountReviewRequest::create(['user_id' => $user->id, 'subject_type' => $source['type'], 'subject_id' => $source['id'],
                'source_token' => $source['source_token'], 'source_snapshot' => $source['snapshot'], 'message' => $message, 'status' => 'pending']);
            foreach (User::where('role', 'admin')->get()->filter(fn ($admin) => $admin->canAccessPortal() && $admin->id !== $user->id) as $admin) {
                app(NotificationDeliveryService::class)->record($admin->id, 'governance-event', 'account-review:requested:'.$result->id,
                    ['title' => 'An account review was requested', 'body' => 'An account owner asked for a review. Open the request to read their explanation.',
                        'target' => 'account-review-admin', 'review_request_id' => $result->id]);
            }

            return $result;
        }, 3);
    }

    public function listing(User $actor, string $status = 'pending')
    {
        $this->restrictions->currentActor($actor);

        return AccountReviewRequest::with(['user:id,name,email,role', 'decision.reviewer'])
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->latest('id')->paginate(15)->withQueryString()
            ->through(fn ($item) => $this->requestData($item) + ['owner' => $item->user->only(['id', 'name', 'email', 'role'])]);
    }

    public function review(User $actor, AccountReviewRequest $item): array
    {
        $this->restrictions->currentActor($actor);
        $user = $item->user()->firstOrFail();
        $source = $user->closed_at === null ? $this->source($user, $item->subject_type, $item->subject_id) : null;
        $stale = ! $source || ! hash_equals($item->source_token, $source['source_token']);
        $actions = [];
        $activity = null;
        if ($item->status === 'pending' && $actor->id !== $user->id) {
            if ($stale) {
                $actions = ['close_stale'];
            } else {
                $actions[] = 'decline';
                if ($item->subject_type === 'account' && $user->kyc_status === 'rejected') {
                    $actions[] = 'reopen_application';
                }
                if ($source['kind'] === 'restriction') {
                    $actions[] = 'reactivate';
                    $activity = $item->subject_type === 'account' ? $this->restrictions->presentation($actor, $user)
                        : app(ResourceRestrictionService::class)->presentation($actor, $item->subject_type, $item->subject_id);
                    $activity['actions'] = ['reactivate'];
                }
            }
        }

        return ['request' => $this->requestData($item->loadMissing('decision.reviewer')), 'owner' => $user->only(['id', 'name', 'email', 'role', 'status', 'kyc_status']),
            'source_reason' => $source['reason'] ?? 'The account or restriction changed after this request was submitted.',
            'stale' => $stale, 'actions' => $actions, 'activity' => $activity,
            'review_token' => $this->restrictions->token(['request_id' => $item->id, 'request_status' => $item->status, 'current_source' => $source['source_token'] ?? null]),
        ];
    }

    public function decide(Request $request, AccountReviewRequest $item): AccountReviewDecision
    {
        $this->restrictions->currentActor($request->user());
        if (is_string($request->input('reason'))) {
            $request->merge(['reason' => trim($request->input('reason'), ' ')]);
        }
        $data = $request->validate(['review_token' => 'required|string|regex:/\A[a-f0-9]{64}\z/',
            'action' => 'required|string|in:reactivate,reopen_application,decline,close_stale',
            'reason' => ['bail', 'required', 'string', new ApplicationText('notes', 5, 1000)],
            'role' => 'prohibited', 'status' => 'prohibited', 'kyc_status' => 'prohibited', 'user_id' => 'prohibited']);

        return DB::transaction(function () use ($request, $item, $data): AccountReviewDecision {
            $this->restrictions->lockGuard();
            $item = AccountReviewRequest::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $actor = $this->restrictions->currentActor($request->user());
            abort_if($actor->id === $item->user_id, 403, 'Another admin must review your request.');
            if ($existing = $item->decision) {
                abort_unless($existing->reviewer_id === $actor->id && $existing->action === $data['action'] && $existing->reason === $data['reason'], 409, 'This request was already reviewed. Reload to see the outcome.');

                return $existing;
            }
            $review = $this->review($actor, $item);
            abort_unless($item->status === 'pending' && hash_equals($review['review_token'], $data['review_token']), 409, 'This request or account changed. Reload before deciding.');
            abort_unless(in_array($data['action'], $review['actions'], true), 409, 'This action is no longer available. Reload the current review.');
            $before = $this->source($item->user, $item->subject_type, $item->subject_id);
            $restriction = null;
            if ($data['action'] === 'reactivate') {
                $input = ['action' => 'reactivate', 'reason' => $data['reason'], 'owner_feedback' => $data['reason'], 'source_token' => $request->input('source_token'),
                    'affected_work_confirmed' => $request->input('affected_work_confirmed')];
                $restriction = $item->subject_type === 'account' ? $this->restrictions->decide($actor, $item->user, $input)
                    : app(ResourceRestrictionService::class)->decide($actor, $item->subject_type, $item->subject_id, $input);
            } else {
                $users = User::whereIn('id', [$actor->id, $item->user_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $actor = $this->restrictions->currentActor($users[$actor->id]);
                $owner = $users[$item->user_id];
                $current = $this->review($actor, $item->fresh());
                abort_unless(hash_equals($current['review_token'], $data['review_token']), 409, 'This account changed. Reload before deciding.');
                if ($data['action'] === 'reopen_application') {
                    abort_unless($owner->closed_at === null && $owner->kyc_status === 'rejected' && ! $owner->isAdmin(), 409);
                    $owner->forceFill(['kyc_status' => 'pending_approval', 'kyc_feedback' => null, 'kyc_reviewed_at' => null, 'kyc_submitted_at' => now()])->save();
                }
            }
            $status = match ($data['action']) {
                'decline' => 'declined', 'close_stale' => 'superseded', default => 'accepted'
            };
            $decision = AccountReviewDecision::create(['review_request_id' => $item->id, 'reviewer_id' => $actor->id,
                'action' => $data['action'], 'reason' => $data['reason'], 'before_state' => $before['snapshot'] ?? [],
                'after_state' => $this->recordState($item->user->fresh(), $item->subject_type, $item->subject_id),
                'restriction_decision_id' => $restriction?->id, 'reviewed_at' => now()]);
            $item->forceFill(['status' => $status, 'resolved_at' => now()])->save();
            $outcome = match ($data['action']) {
                'reactivate' => 'The restriction you asked about was lifted. Any separate account, shop, company or assignment restriction still applies.',
                'reopen_application' => 'Your application is back in the review queue. It still needs approval; any separate suspension or deactivation stays in place.',
                'decline' => 'Your review request was declined. Your current approval and activity status are unchanged.',
                default => 'Your account status changed before this request was reviewed. Check your current status for the next step.',
            };
            $delivery = app(NotificationDeliveryService::class)->record($item->user_id, 'governance-event', 'account-review:outcome:'.$decision->id,
                ['title' => 'Your review request was updated', 'body' => $outcome."\nAdmin response: ".$decision->reason,
                    'target' => 'account-status', 'review_request_id' => $item->id]);
            app(AccountStatusDeliveryService::class)->requireEmail($delivery);

            return $decision;
        }, 3);
    }

    private function recordState(User $user, string $type, int $id): array
    {
        $resource = match ($type) {
            'account' => $user, 'shop' => Shop::find($id), 'company' => LogisticsCompany::find($id)
        };

        return ['owner' => $user->only(['id', 'role', 'status', 'kyc_status', 'restriction_version', 'identity_version', 'closed_at']),
            'subject' => $resource?->only(['id', 'status', 'restriction_version'])];
    }
}
