<?php

namespace App\Services;

use App\Models\IdentityCorrectionRequest;
use App\Models\KycDecision;
use App\Models\Shop;
use App\Models\ShopReviewDecision;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ShopReviewService
{
    public function __construct(private VerificationDocumentService $documents, private ShopEligibilityService $eligibility) {}

    public function values(Shop $shop): array
    {
        return ['shop_name' => $shop->name, 'shop_phone' => $shop->phone, 'shop_address' => $shop->address,
            'shop_city' => $shop->city, 'root_category_id' => $shop->root_category_id];
    }

    public function rules(): array
    {
        return array_intersect_key(app(ApplicationValidationService::class)->rules('seller'), array_flip(['shop_name', 'shop_phone', 'shop_address', 'shop_city', 'root_category_id']));
    }

    public function submission(Shop $shop): array
    {
        $owner = $shop->user()->firstOrFail();
        $evidenceOwner = clone $owner;
        $evidenceOwner->setRawAttributes(array_replace($owner->getAttributes(), ['business_permit_path' => $shop->business_permit_path]), true);

        return [
            'shop' => $shop->only(['id', 'user_id', ...ShopEligibilityService::DETAILS]),
            'submitted_at' => $shop->review_submitted_at?->toISOString(),
            'version' => $shop->review_version ?? 0,
            'owner' => [...$owner->only(['id', 'role', 'kyc_status', ...ApplicationValidationService::ACCOUNT_FIELDS]), 'birthday' => $owner->birthday?->toDateString()],
            'category' => app(MasterCategoryService::class)->snapshot($shop->root_category_id),
            'documents' => array_intersect_key($this->documents->evidence($evidenceOwner), array_flip(['id', 'permit'])),
        ];
    }

    public function token(array $submission): string
    {
        return hash_hmac('sha256', json_encode($submission, JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function issues(Shop $shop, array $submission): array
    {
        $values = app(ApplicationValidationService::class)->normalize($this->values($shop), 'seller');
        $issues = array_merge(...array_values(Validator::make($values, $this->rules())->errors()->toArray())) ?: [];
        $owner = $shop->user()->firstOrFail();
        if (! $owner->isSeller() || ! $owner->isKycApproved()) {
            $issues[] = 'The seller account must already have identity approval.';
        }
        if ($issue = app(BirthDateEligibility::class)->issue($owner->birthday, true)) {
            $issues[] = $issue;
        }
        foreach (['id', 'permit'] as $kind) {
            if (! ($submission['documents'][$kind]['valid'] ?? false)) {
                $issues[] = "A valid private {$kind} document is required.";
            }
        }

        return array_values(array_unique($issues));
    }

    public function presentation(Shop $shop): array
    {
        $submission = $this->submission($shop);

        return [...$shop->toArray(), 'eligible' => $this->eligibility->isEligible($shop),
            'review_token' => $this->token($submission), 'review_issues' => $this->issues($shop, $submission),
            'owner_birthday' => $submission['owner']['birthday'], 'owner_age' => app(BirthDateEligibility::class)->age($submission['owner']['birthday']),
            'can_submit' => in_array($shop->review_status, [null, 'pending_approval', 'rejected'], true),
            'documents' => $this->links($shop, $submission),
            'decision_history' => $shop->reviewDecisions()->orderByDesc('id')->get()->map(fn ($decision) => [
                'id' => $decision->id, 'decision' => $decision->decision, 'reason' => $decision->reason,
                'reviewer' => $decision->reviewer_name, 'reviewed_at' => $decision->reviewed_at->toISOString(),
                'shop_status' => $decision->after_state['status'], 'source' => $decision->kyc_decision_id ? 'Original account application' : 'Independent shop review',
                'documents' => $this->links($shop, $decision->submission, $decision),
            ])->all()];
    }

    public function links(Shop $shop, array $submission, ?ShopReviewDecision $decision = null): array
    {
        $links = [];
        foreach (['id', 'permit'] as $kind) {
            $path = $submission['documents'][$kind]['path'] ?? null;
            $extension = $path ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : null;
            $links[$kind] = $extension && in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)
                ? route('shop-verification-documents.show', array_filter(['shop' => $shop->id, 'document' => "$kind.$extension", 'decision' => $decision?->id]), false) : null;
        }

        return $links;
    }

    public function submit(Request $request, ?Shop $subject = null): Shop
    {
        $data = $request->all();
        $data = array_replace($data, ['shop_name' => $data['name'] ?? null, 'shop_phone' => $data['phone'] ?? null,
            'shop_address' => $data['address'] ?? null, 'shop_city' => $data['city'] ?? null]);
        $data = app(ApplicationValidationService::class)->normalize($data, 'seller');
        $validated = Validator::make($data, $this->rules() + [
            'description' => 'nullable|string|max:1000', 'business_permit' => [$subject ? 'nullable' : 'required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'review_token' => [$subject ? 'required' : 'nullable', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'status' => 'prohibited', 'review_status' => 'prohibited', 'approved' => 'prohibited', 'review_decision_id' => 'prohibited',
        ])->validate();
        $paths = $this->documents->storeUploads($validated);
        try {
            return DB::transaction(function () use ($request, $subject, $validated, $paths) {
                $owner = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                abort_unless($owner->isSeller() && $owner->canAccessPortal(), 403);
                $shop = $subject ? Shop::where('user_id', $owner->id)->whereKey($subject->id)->lockForUpdate()->firstOrFail() : null;
                $this->eligibility->lockCategories();
                app(MasterCategoryService::class)->requireEligible($validated['root_category_id']);
                if ($shop) {
                    abort_unless(in_array($shop->review_status, [null, 'pending_approval', 'rejected'], true), 409, 'Reviewed shop changes require a separate correction review.');
                    abort_unless(hash_equals($this->token($this->submission($shop)), $validated['review_token']), 409, 'This shop changed. Reload its current details before submitting.');
                }
                $permit = $paths['business_permit_path'] ?? $shop?->business_permit_path;
                if (! $permit) {
                    throw ValidationException::withMessages(['business_permit' => 'Upload the business permit for this shop.']);
                }
                $values = ['name' => $validated['shop_name'], 'phone' => $validated['shop_phone'], 'address' => $validated['shop_address'],
                    'city' => $validated['shop_city'], 'root_category_id' => $validated['root_category_id'],
                    'business_permit_path' => $permit, 'review_status' => 'pending_approval',
                    'review_version' => ($shop?->review_version ?? 0) + 1,
                    'review_submitted_at' => now(), 'review_feedback' => null, 'reviewed_at' => null, 'review_decision_id' => null];
                if (array_key_exists('description', $validated)) {
                    $values['description'] = $validated['description'];
                }
                if ($shop) {
                    // Preserve activity restrictions, product references, and previous immutable reviews.
                    $shop->update($values);
                } else {
                    $shop = Shop::create($values + ['user_id' => $owner->id, 'status' => 'pending',
                        'is_default' => false, 'slug' => Str::slug(Str::limit($values['name'], 180, '')).'-'.$owner->id.'-'.Str::lower(Str::random(12))]);
                }

                return $shop;
            }, 3);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete(array_values($paths));
            throw $exception;
        }
    }

    public function inspect(Request $request, Shop $shop, string $document, array $servedSubmission): void
    {
        $actor = User::find($request->user()->id);
        if (! $actor?->isAdmin() || ! $actor->canAccessPortal() || $shop->review_status !== 'pending_approval') {
            return;
        }
        $shop = $shop->fresh();
        $submission = $this->submission($shop);
        if (! hash_equals($this->token($servedSubmission), $this->token($submission)) || $shop->review_status !== 'pending_approval') {
            return;
        }
        $kind = pathinfo($document, PATHINFO_FILENAME);
        if (! ($submission['documents'][$kind]['valid'] ?? false)) {
            return;
        }
        $key = 'shop_inspection.'.$shop->id;
        $token = $this->token($submission);
        $inspection = $request->session()->get($key, []);
        if (($inspection['token'] ?? null) !== $token || ($inspection['actor'] ?? null) !== $actor->id) {
            $inspection = ['token' => $token, 'actor' => $actor->id, 'documents' => []];
        }
        $inspection['documents'][$kind] = $submission['documents'][$kind]['sha256'];
        $request->session()->put($key, $inspection);
    }

    public function decide(Request $request, Shop $subject, string $action, array $validated): ShopReviewDecision
    {
        abort_unless(in_array($action, ['approved', 'rejected'], true), 400);

        return DB::transaction(function () use ($request, $subject, $action, $validated) {
            $users = User::whereIn('id', [$request->user()->id, $subject->user_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = $users->get($request->user()->id);
            $owner = $users->get($subject->user_id);
            abort_unless($actor?->isAdmin() && $actor->canAccessPortal() && $owner?->isSeller(), 403);
            $shop = Shop::whereKey($subject->id)->where('user_id', $owner->id)->lockForUpdate()->firstOrFail();
            $this->eligibility->lockCategories();
            $submission = $this->submission($shop);
            $token = $this->token($submission);
            $reason = $action === 'rejected' ? $validated['reason'] : null;
            $existing = $shop->reviewDecisions()->where('submission_token', $validated['review_token'])->first();
            if ($existing) {
                $current = $submission;
                $reviewed = $existing->submission;
                unset($current['category'], $reviewed['category']);
                abort_unless(hash_equals($this->token($current), $this->token($reviewed))
                    && $existing->decision === $action && $existing->reason === $reason && $existing->reviewer_id === $actor->id,
                    409, 'This submission has already been reviewed or changed. Reload the shop review.');

                return $existing;
            }
            abort_unless(hash_equals($token, $validated['review_token']) && $shop->review_status === 'pending_approval',
                409, 'This shop changed. Reload its current submission and inspect the evidence again.');
            if ($action === 'approved') {
                if ($issues = $this->issues($shop, $submission)) {
                    throw ValidationException::withMessages(['review' => implode(' ', $issues)]);
                }
                $inspection = $request->session()->get('shop_inspection.'.$shop->id, []);
                foreach (['id', 'permit'] as $kind) {
                    if (($inspection['token'] ?? null) !== $token || ($inspection['actor'] ?? null) !== $actor->id
                        || ($inspection['documents'][$kind] ?? null) !== $submission['documents'][$kind]['sha256']) {
                        throw ValidationException::withMessages(['review' => 'Open and inspect both current private documents in this session before approving.']);
                    }
                }
            }

            return $this->record($shop, $owner, $actor, $action, $reason, $submission);
        }, 3);
    }

    public function recordOriginal(Shop $shop, User $owner, User $actor, KycDecision $kyc, ?array $before = null): ShopReviewDecision
    {
        // The original permit was actually inspected during this account review; extra shops stay untouched.
        $shop->update(['business_permit_path' => $kyc->submission['documents']['permit']['path'],
            'review_submitted_at' => $owner->kyc_submitted_at, 'review_version' => ($shop->review_version ?? 0) + 1]);

        return $this->record($shop, $owner, $actor, $kyc->decision, $kyc->reason, $this->submission($shop), $kyc, $before);
    }

    public function recordCorrection(Shop $shop, User $owner, User $actor, IdentityCorrectionRequest $correction, array $before, string $reason): ShopReviewDecision
    {
        if ($issues = $this->issues($shop, $this->submission($shop))) {
            throw ValidationException::withMessages(['review' => implode(' ', $issues)]);
        }

        return $this->record($shop, $owner, $actor, 'approved', $reason, $this->submission($shop), before: $before, correction: $correction);
    }

    private function record(Shop $shop, User $owner, User $actor, string $action, ?string $reason, array $submission, ?KycDecision $kyc = null, ?array $before = null, ?IdentityCorrectionRequest $correction = null): ShopReviewDecision
    {
        $before ??= $shop->only(['status', 'review_status', 'reviewed_at', 'review_feedback']);
        $status = $shop->status;
        if (! $correction && $action === 'approved' && $status === 'pending' && $owner->canAccessPortal()) {
            $status = 'active';
        }
        $shop->update(['review_status' => $action, 'status' => $status, 'reviewed_at' => $kyc?->reviewed_at ?? now(), 'review_feedback' => $reason]);
        $decision = ShopReviewDecision::create([
            'shop_id' => $shop->id, 'seller_id' => $owner->id, 'reviewer_id' => $actor->id,
            'kyc_decision_id' => $kyc?->id, 'root_category_id' => $shop->root_category_id,
            'identity_correction_request_id' => $correction?->id,
            'reviewer_role' => $actor->role, 'reviewer_name' => $actor->name,
            'submission_token' => $this->token($submission), 'decision' => $action, 'reason' => $reason,
            'submission' => $submission, 'before_state' => $before,
            'after_state' => $shop->only(['status', 'review_status', 'reviewed_at', 'review_feedback']), 'reviewed_at' => $shop->reviewed_at,
        ]);
        $shop->update(['review_decision_id' => $decision->id]);

        return $decision;
    }
}
