<?php

namespace App\Services;

use App\Models\IdentityCorrectionDecision;
use App\Models\IdentityCorrectionRequest;
use App\Models\KycDecision;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Rules\BirthDate;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IdentityCorrectionService
{
    public const IDENTITY_FIELDS = ['name', 'first_name', 'last_name', 'middle_name', 'sex', 'birthday'];

    public function __construct(private AccountRestrictionService $restrictions, private VerificationDocumentService $documents) {}

    public function fields(User $user): array
    {
        return [...self::IDENTITY_FIELDS, ...match ($user->role) {
            'seller' => ['shop_name', 'shop_phone', 'shop_address', 'shop_city', 'root_category_id'],
            'courier' => ['vehicle_type', 'plate_number', 'license_number'],
            'logistics' => ['company_name', 'company_code'],
            default => [],
        }];
    }

    public function protectReviewedIdentity(User $user, array $values): void
    {
        if (! $user->isKycApproved() && ! KycDecision::where('user_id', $user->id)->where('decision', 'approved')->exists()) {
            return;
        }
        foreach (self::IDENTITY_FIELDS as $field) {
            if (array_key_exists($field, $values) && (string) $values[$field] !== (string) ($field === 'birthday' ? $user->birthday?->toDateString() : $user->$field)) {
                throw ValidationException::withMessages([$field => 'Reviewed identity needs a separate correction request with evidence.']);
            }
        }
    }

    public function mutateProfile(Request $request, Closure $work): mixed
    {
        return DB::transaction(function () use ($request, $work) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $request->setUserResolver(fn () => $user);

            return $work();
        }, 3);
    }

    private function authorize(User $actor, User $subject): User
    {
        $actor = User::findOrFail($actor->id);
        abort_unless($actor->id === $subject->id || ($actor->isAdmin() && $actor->canAccessPortal()), 403);
        abort_unless($actor->closed_at === null && $subject->closed_at === null, 409, 'Closed accounts retain their recorded identity. A new correction cannot reopen them.');
        abort_unless(in_array($subject->role, ['buyer', 'seller', 'courier', 'logistics', 'admin'], true), 409, 'The account role needs controlled review.');
        abort_unless($subject->isKycApproved(), 409, 'Use application resubmission until identity approval is complete.');

        return $actor;
    }

    private function shop(User $user, ?int $shopId): ?Shop
    {
        abort_if($shopId && ! $user->isSeller(), 422);

        return $user->isSeller() ? Shop::where('user_id', $user->id)->when($shopId, fn ($query) => $query->whereKey($shopId))->orderBy('id')->firstOrFail() : null;
    }

    public function source(User $user, ?Shop $shop): array
    {
        $user = $user->fresh(['courierProfile', 'logisticsCompany']);
        $user->setRelation('shop', $shop?->fresh());

        $evidenceOwner = clone $user;
        if ($shop) {
            $evidenceOwner->setRawAttributes(array_replace($user->getAttributes(), ['business_permit_path' => $shop->business_permit_path]), true);
        }

        return ['account' => $user->only(['id', 'role', 'status', 'kyc_status', 'kyc_reviewed_at', 'identity_version', 'restriction_version']),
            'values' => app(ApplicationValidationService::class)->values($user),
            'shop' => $shop?->fresh()->only(['id', 'user_id', 'status', 'review_status', 'review_version', 'review_decision_id', 'restriction_version']),
            'courier' => $user->courierProfile?->only(['id', 'is_available', 'logistics_company_id', 'assigned_hub_id', 'vehicle_id']),
            'company' => $user->logisticsCompany?->only(['id', 'status', 'is_active', 'restriction_version']),
            'documents' => $this->documents->evidence($evidenceOwner)];
    }

    public function form(User $actor, User $subject, ?int $shopId = null): array
    {
        $subject = $subject->fresh();
        $this->authorize($actor, $subject);
        $shop = $this->shop($subject, $shopId);
        $source = $this->source($subject, $shop);
        $rules = app(ApplicationValidationService::class)->form($subject);

        return ['id' => $subject->id, 'name' => $subject->name, 'role' => $subject->role,
            'shop_id' => $shop?->id, 'shops' => $subject->isSeller() ? Shop::where('user_id', $subject->id)->orderBy('id')->get(['id', 'name'])->toArray() : [],
            'source_token' => $this->restrictions->token($source),
            'values' => array_intersect_key($source['values'], array_flip($this->fields($subject))),
            'fields' => array_values(array_filter($rules['fields'], fn ($field) => in_array($field['key'], $this->fields($subject), true))),
            'required_documents' => $this->requiredDocuments($subject, $source['documents']),
            'provenance' => $this->provenance($subject, $shop),
            'requests' => IdentityCorrectionRequest::where('user_id', $subject->id)->with('decision')->latest('id')->get()->map(fn ($correction) => $this->presentation($correction))->all()];
    }

    private function requiredDocuments(User $subject, array $evidence): array
    {
        $kinds = match ($subject->role) {
            'seller' => ['id', 'permit'], 'courier' => ['id', 'license', 'orcr'], 'logistics' => ['permit'], default => ['id'],
        };
        $franchise = $subject->logisticsCompany?->accreditation_details['franchise_number'] ?? null;
        if ($subject->isLogistics() && (filled($evidence['franchise']['path'] ?? null) || (filled($franchise) && $franchise !== 'PENDING-LTFRB'))) {
            $kinds[] = 'franchise';
        }

        return $kinds;
    }

    private function provenance(User $subject, ?Shop $shop): array
    {
        $prior = KycDecision::where('user_id', $subject->id)->latest('id')->first();

        return ['source' => $prior ? 'recorded_review' : 'legacy_without_recorded_review', 'kyc_decision_id' => $prior?->id,
            'shop_review_decision_id' => $shop?->review_decision_id, 'identity_version' => $subject->identity_version];
    }

    public function submit(Request $request, User $subject): IdentityCorrectionRequest
    {
        $this->authorize($request->user(), $subject->fresh());
        $input = $request->all();
        $input['changes'] = is_array($input['changes'] ?? null) ? app(ApplicationValidationService::class)->normalize($input['changes'], $subject->role) : ($input['changes'] ?? null);
        $input['reason'] = is_string($input['reason'] ?? null) ? trim($input['reason']) : $input['reason'] ?? null;
        $rules = ['source_token' => 'required|string|regex:/\A[a-f0-9]{64}\z/', 'shop_id' => 'nullable|integer|min:1',
            'changes' => ['required', 'array:'.implode(',', $this->fields($subject)), 'min:1'],
            'reason' => ['required', 'string', new ApplicationText('notes', 5, 1000)],
            'role' => 'prohibited', 'status' => 'prohibited', 'kyc_status' => 'prohibited', 'identity_version' => 'prohibited'];
        $fieldRules = app(ApplicationValidationService::class)->rules($subject->role, $subject);
        // A truthful correction may prove a worker is underage. Record it and deny eligibility; never falsify the date.
        $fieldRules['birthday'] = ['required', new BirthDate];
        foreach ($this->fields($subject) as $field) {
            $rules['changes.'.$field] = ['sometimes', ...($fieldRules[$field] ?? ['nullable', 'string', 'max:100'])];
        }
        foreach (['id_document', 'business_permit', 'driver_license', 'or_cr_document', 'franchise_document'] as $field) {
            $rules[$field] = ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'];
        }
        $data = Validator::make($input, $rules)->validate();
        $paths = $this->documents->storeUploads($data);
        $used = false;
        try {
            $result = DB::transaction(function () use ($request, $subject, $data, $paths) {
                $users = User::whereIn('id', [$request->user()->id, $subject->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $subject = $users->get($subject->id);
                abort_unless($subject, 404);
                $actor = $this->authorize($users->get($request->user()->id), $subject);
                app(KycDecisionService::class)->lockProfile($subject);
                $shop = $this->shop($subject, $data['shop_id'] ?? null);
                if ($shop) {
                    $shop = Shop::whereKey($shop->id)->lockForUpdate()->firstOrFail();
                    app(ShopEligibilityService::class)->lockCategories();
                    $subject->setRelation('shop', $shop);
                }
                $source = $this->source($subject, $shop);
                $evidenceOwner = clone $subject;
                $basePaths = $subject->getAttributes();
                if ($shop) {
                    $basePaths['business_permit_path'] = $shop->business_permit_path;
                }
                $evidenceOwner->setRawAttributes(array_replace($basePaths, $paths), true);
                if (isset($paths['franchise_document_path']) && $subject->logisticsCompany) {
                    $company = clone $subject->logisticsCompany;
                    $company->accreditation_details = [...($company->accreditation_details ?? []), 'franchise_document_path' => $paths['franchise_document_path']];
                    $evidenceOwner->setRelation('logisticsCompany', $company);
                }
                $evidence = $this->documents->evidence($evidenceOwner);
                $fingerprints = array_map(fn ($file) => $file['sha256'], $evidence);
                $token = $this->restrictions->token([$actor->id, $shop?->id, $data['source_token'], $data['changes'], $data['reason'], $fingerprints]);
                if ($existing = IdentityCorrectionRequest::where('user_id', $subject->id)->where('request_token', $token)->first()) {
                    return $existing;
                }
                abort_unless(hash_equals($this->restrictions->token($source), $data['source_token']), 409, 'The reviewed details changed. Reload before requesting a correction.');
                if (! array_filter($data['changes'], fn ($value, $field) => (string) $value !== (string) ($source['values'][$field] ?? null), ARRAY_FILTER_USE_BOTH)) {
                    throw ValidationException::withMessages(['changes' => 'Propose at least one actual correction.']);
                }
                foreach ($this->requiredDocuments($subject, $evidence) as $kind) {
                    if (! ($evidence[$kind]['valid'] ?? false)) {
                        throw ValidationException::withMessages(['evidence' => "Upload a valid private {$kind} document for this correction."]);
                    }
                }
                $correction = IdentityCorrectionRequest::create(['user_id' => $subject->id, 'shop_id' => $shop?->id,
                    'requester_id' => $actor->id, 'version' => $subject->identity_version + 1,
                    'source_token' => $data['source_token'], 'request_token' => $token, 'reason' => $data['reason'],
                    'source' => $source, 'proposed' => $data['changes'], 'evidence' => $evidence,
                    'provenance' => $this->provenance($subject, $shop), 'requested_at' => now()]);

                return $correction;
            }, 3);
            $used = (bool) array_intersect(array_values($paths), array_column($result->evidence, 'path'));

            return $result;
        } finally {
            if (! $used) {
                Storage::disk('local')->delete(array_values($paths));
            }
        }
    }

    private function currentEvidence(IdentityCorrectionRequest $correction): array
    {
        $owner = User::findOrFail($correction->user_id);
        $owner->setRawAttributes(array_replace($owner->getAttributes(), array_combine(array_values(VerificationDocumentService::FIELDS), array_map(fn ($kind) => $correction->evidence[$kind]['path'] ?? null, array_keys(VerificationDocumentService::FIELDS)))), true);
        if ($owner->logisticsCompany) {
            $owner->logisticsCompany->accreditation_details = [...($owner->logisticsCompany->accreditation_details ?? []), 'franchise_document_path' => $correction->evidence['franchise']['path'] ?? null];
        }

        return $this->documents->evidence($owner);
    }

    public function reviewToken(IdentityCorrectionRequest $correction): string
    {
        return $this->restrictions->token([$correction->id, $correction->source_token, $correction->proposed, $correction->reason,
            $this->currentEvidence($correction), $this->restrictions->state(User::findOrFail($correction->user_id))]);
    }

    public function presentation(IdentityCorrectionRequest $correction): array
    {
        $decision = $correction->decision;
        $subject = User::findOrFail($correction->user_id);
        $required = $this->requiredDocuments($subject, $correction->evidence);
        $shop = $correction->shop_id ? Shop::find($correction->shop_id) : null;
        $current = hash_equals($correction->source_token, $this->restrictions->token($this->source($subject, $shop)))
            && $correction->version === $subject->identity_version + 1
            && $this->currentEvidence($correction) === $correction->evidence;
        $links = [];
        foreach ($required as $kind) {
            $extension = pathinfo($correction->evidence[$kind]['path'] ?? '', PATHINFO_EXTENSION);
            $links[$kind] = $extension ? route('identity-correction-documents.show', ['correction' => $correction->id, 'document' => $kind.'.'.$extension], false) : null;
        }

        return ['id' => $correction->id, 'version' => $correction->version, 'shop_id' => $correction->shop_id,
            'reason' => $correction->reason, 'requested_at' => $correction->requested_at->toISOString(),
            'before' => array_intersect_key($correction->source['values'], $correction->proposed), 'proposed' => $correction->proposed,
            'provenance' => $correction->provenance, 'documents' => $links, 'current' => $current, 'review_token' => $this->reviewToken($correction),
            'decision' => $decision ? ['id' => $decision->id, 'action' => $decision->action, 'reason' => $decision->reason,
                'actor' => $decision->actor_name, 'decided_at' => $decision->decided_at->toISOString(),
                'eligible' => $decision->after_state['eligible'] ?? false] : null];
    }

    public function document(Request $request, IdentityCorrectionRequest $correction, string $document): StreamedResponse
    {
        $actor = User::findOrFail($request->user()->id);
        abort_unless($actor->id === $correction->user_id || ($actor->isAdmin() && $actor->canAccessPortal()), 403);
        abort_unless(preg_match('/\A(id|permit|license|orcr|franchise)\.(jpg|jpeg|png|webp|pdf)\z/', $document, $parts), 404);
        $file = $correction->evidence[$parts[1]] ?? null;
        $path = $file['path'] ?? null;
        abort_unless($path && preg_match('/\Akyc_documents\/[A-Za-z0-9._-]+\.(jpg|jpeg|png|webp|pdf)\z/i', $path)
            && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === $parts[2] && Storage::disk('local')->exists($path), 404);
        $current = $this->currentEvidence($correction)[$parts[1]];
        abort_unless($current['valid'] && $current['sha256'] === $file['sha256'], 409, 'The correction document changed. Submit current evidence in a new request.');
        if ($actor->isAdmin() && $actor->canAccessPortal()) {
            $key = 'identity_inspection.'.$correction->id;
            $token = $this->reviewToken($correction);
            $inspection = $request->session()->get($key, []);
            if (($inspection['actor'] ?? null) !== $actor->id || ($inspection['token'] ?? null) !== $token) {
                $inspection = ['actor' => $actor->id, 'token' => $token, 'documents' => []];
            }
            $inspection['documents'][$parts[1]] = $current['sha256'];
            $request->session()->put($key, $inspection);
        }

        return Storage::disk('local')->response($path, $document, ['Content-Type' => $current['mime'],
            'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    public function decide(Request $request, IdentityCorrectionRequest $correction): IdentityCorrectionDecision
    {
        $this->restrictions->currentActor($request->user());
        $data = Validator::make($request->all(), ['action' => 'required|in:approve,reject',
            'review_token' => 'required|string|regex:/\A[a-f0-9]{64}\z/',
            'reason' => ['required', 'string', new ApplicationText('notes', 5, 1000)],
            'affected_work_confirmed' => 'required|accepted'])->validate();

        return DB::transaction(function () use ($request, $correction, $data) {
            $this->restrictions->lockGuard();
            $subject = User::findOrFail($correction->user_id);
            $workIds = $this->restrictions->ordersFor($subject)->orderBy('id')->pluck('id');
            $this->restrictions->lockWork($workIds);
            $users = User::where(fn ($query) => $query->whereIn('id', [$request->user()->id, $subject->id])->orWhere('role', 'admin'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $subject = $users->get($subject->id);
            abort_unless($subject->closed_at === null, 409, 'A closed account cannot receive a new identity decision.');
            $actor = $this->restrictions->currentActor($users->get($request->user()->id));
            app(KycDecisionService::class)->lockProfile($subject);
            $shop = $this->shop($subject, $correction->shop_id);
            if ($shop) {
                $shop = Shop::whereKey($shop->id)->lockForUpdate()->firstOrFail();
                $subject->setRelation('shop', $shop);
                app(ShopEligibilityService::class)->lockCategories();
            }
            $correction = IdentityCorrectionRequest::whereKey($correction->id)->lockForUpdate()->firstOrFail();
            if ($decision = $correction->decision) {
                abort_unless($decision->actor_id === $actor->id && $decision->action === $data['action'] && $decision->reason === $data['reason'] && hash_equals($decision->review_token, $data['review_token']), 409, 'This request was already decided differently.');

                return $decision;
            }
            $source = $this->source($subject, $shop);
            abort_unless(hash_equals($this->restrictions->token($source), $correction->source_token)
                && $correction->version === $subject->identity_version + 1
                && $workIds->all() === $this->restrictions->ordersFor($subject)->orderBy('id')->pluck('id')->all(), 409, 'Identity or responsibilities changed. Submit a new correction request.');
            $token = $this->reviewToken($correction);
            abort_unless(hash_equals($token, $data['review_token']) && $this->currentEvidence($correction) === $correction->evidence, 409, 'The proposal or evidence changed. Submit a new request.');
            $before = ['identity' => $source, 'work' => $this->restrictions->state($subject), 'eligible' => $subject->canAccessPortal()];
            if ($data['action'] === 'approve') {
                $inspection = $request->session()->get('identity_inspection.'.$correction->id, []);
                foreach ($this->requiredDocuments($subject, $correction->evidence) as $kind) {
                    if (($inspection['actor'] ?? null) !== $actor->id || ($inspection['token'] ?? null) !== $token || ($inspection['documents'][$kind] ?? null) !== $correction->evidence[$kind]['sha256']) {
                        throw ValidationException::withMessages(['review' => 'Open every required private document in this session before approving.']);
                    }
                }
                $this->apply($subject, $shop, $correction);
                if ($subject->isAdmin() && $before['eligible'] && ! $subject->canAccessPortal()) {
                    abort_unless($users->contains(fn ($admin) => $admin->id !== $subject->id && $admin->isAdmin() && $admin->canAccessPortal()), 409, 'Arrange a controlled eligible admin replacement before correcting the final admin. Never alter the evidence to retain access.');
                }
            }
            if ($data['action'] === 'approve' && $shop && array_intersect(array_keys($correction->proposed), ['shop_name', 'shop_phone', 'shop_address', 'shop_city', 'root_category_id'])) {
                app(ShopReviewService::class)->recordCorrection($shop, $subject, $actor, $correction, $before['identity']['shop'], $data['reason']);
            }
            $decision = IdentityCorrectionDecision::create(['correction_request_id' => $correction->id, 'actor_id' => $actor->id,
                'actor_name' => $actor->name, 'review_token' => $token, 'action' => $data['action'], 'reason' => $data['reason'],
                'before_state' => $before, 'after_state' => ['identity' => $this->source($subject, $shop), 'work' => $this->restrictions->state($subject), 'eligible' => $subject->canAccessPortal()], 'decided_at' => now()]);

            return $decision;
        }, 3);
    }

    private function apply(User $subject, ?Shop $shop, IdentityCorrectionRequest $correction): void
    {
        $values = array_intersect_key($correction->proposed, array_flip(self::IDENTITY_FIELDS));
        $values['identity_version'] = $subject->identity_version + 1;
        foreach (array_intersect_key(VerificationDocumentService::FIELDS, array_flip($this->requiredDocuments($subject, $correction->evidence))) as $kind => $field) {
            if ($field !== 'franchise_document_path' && ! ($shop && $kind === 'permit')) {
                $values[$field] = $correction->evidence[$kind]['path'];
            }
        }
        $subject->forceFill($values)->save();
        if ($shop && array_intersect(array_keys($correction->proposed), ['shop_name', 'shop_phone', 'shop_address', 'shop_city', 'root_category_id'])) {
            $values = [];
            foreach (['shop_name' => 'name', 'shop_phone' => 'phone', 'shop_address' => 'address', 'shop_city' => 'city', 'root_category_id' => 'root_category_id'] as $input => $field) {
                if (array_key_exists($input, $correction->proposed)) {
                    $values[$field] = $correction->proposed[$input];
                }
            }
            if (isset($values['root_category_id'])) {
                app(MasterCategoryService::class)->requireEligible($values['root_category_id']);
            }
            $shop->update($values + ['business_permit_path' => $correction->evidence['permit']['path'], 'review_version' => $shop->review_version + 1]);
        }
        if ($subject->isCourier()) {
            $values = array_intersect_key($correction->proposed, array_flip(['vehicle_type', 'plate_number', 'license_number']));
            if ($values) {
                abort_unless($subject->courierProfile, 409, 'The reviewed rider profile is missing.');
                $subject->courierProfile->update($values);
            }
        }
        if ($subject->isLogistics()) {
            $values = [];
            foreach (['company_name' => 'name', 'company_code' => 'code'] as $input => $field) {
                if (array_key_exists($input, $correction->proposed)) {
                    $values[$field] = $correction->proposed[$input];
                }
            }
            if ($values || filled($correction->evidence['franchise']['path'] ?? null)) {
                abort_unless($subject->logisticsCompany, 409, 'The reviewed company profile is missing.');
                if (filled($correction->evidence['franchise']['path'] ?? null)) {
                    $values['accreditation_details'] = [...($subject->logisticsCompany->accreditation_details ?? []), 'franchise_document_path' => $correction->evidence['franchise']['path']];
                }
                $subject->logisticsCompany->update($values);
            }
        }
    }

    public function affectedListings(User $actor, User $subject, ?int $shopId): array
    {
        $this->restrictions->currentActor($actor);
        $shop = $this->shop($subject, $shopId);

        return $shop ? Product::where('shop_id', $shop->id)->orderBy('id')->get(['id', 'name', 'category_id', 'status'])->toArray() : [];
    }
}
