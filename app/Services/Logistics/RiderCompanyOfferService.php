<?php

namespace App\Services\Logistics;

use App\Models\CourierProfile;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsPlacementRecord;
use App\Models\RiderCompanyConsent;
use App\Models\RiderCompanyOffer;
use App\Models\RiderCompanyRequest;
use App\Models\RiderCompanyRequestEvent;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Services\Notifications\NotificationDeliveryService;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RiderCompanyOfferService
{
    use CompanyRequestInput;

    public function __construct(private readonly RiderCompanyRequestService $requests) {}

    public function available(): bool
    {
        return $this->requests->available() && Schema::hasTable('rider_company_offers')
            && Schema::hasTable('rider_company_consents') && Schema::hasTable('rider_company_scope_changes') && Schema::hasColumn('logistics_placement_records', 'rider_company_consent_id');
    }

    public function issue(User $actor, mixed $requestId, array $input): array
    {
        $this->requireAvailable();
        $data = $this->validate($input, ['expected_version' => ['required', $this->number()], 'request_token' => $this->token(),
            'hub_id' => ['required', $this->number()], 'vehicle_id' => ['nullable', $this->number()],
            'barangay' => ['nullable', 'string', new ApplicationText('notes', 1, 255)],
            'terms' => ['required', 'string', new ApplicationText('notes', 5, 2000)]], ['terms' => 2000, 'barangay' => 255]);
        $source = $this->companyRequest($actor, $requestId);
        $intent = ['action' => 'offered', 'request_id' => $source->id, 'expected_version' => (int) $data['expected_version'],
            'hub_id' => (int) $data['hub_id'], 'vehicle_id' => isset($data['vehicle_id']) ? (int) $data['vehicle_id'] : null,
            'barangay' => $data['barangay'] ?? null, 'terms' => $data['terms']];
        $fingerprint = $this->fingerprint($intent);

        return DB::transaction(function () use ($actor, $source, $data, $intent, $fingerprint) {
            [$company, $request] = $this->locked($source);
            abort_unless($company->user_id === $actor->id && $this->requests->companyOwner($actor), 403);
            if ($receipt = $this->replay($actor, $request, $data['request_token'], $fingerprint)) {
                return $receipt;
            }
            $this->current($request, (int) $data['expected_version'], ['pending', 'offered']);
            $this->assertIntent($request, $intent['hub_id'], $intent['vehicle_id'], $intent['barangay']);
            $sources = $this->sources($request, $intent['hub_id'], $intent['vehicle_id']);
            $hub = LogisticsHub::findOrFail($intent['hub_id']);
            $vehicle = $intent['vehicle_id'] ? LogisticsFleet::findOrFail($intent['vehicle_id']) : null;
            $issuedAt = now()->toImmutable();
            $offer = RiderCompanyOffer::create(['reference' => 'RO-'.Str::ulid(), 'rider_company_request_id' => $request->id,
                'issuer_id' => $actor->id, 'version' => $request->offers()->count() + 1,
                'hub_id' => $hub->id, 'vehicle_id' => $vehicle?->id, 'barangay' => $intent['barangay'],
                'terms' => $intent['terms'], 'terms_hash' => hash('sha256', $intent['terms']),
                'sources' => $sources, 'source_hash' => $this->sourceHash($sources),
                'display' => ['company' => $company->name, 'hub' => $hub->name, 'hub_code' => $hub->code,
                    'vehicle' => $vehicle ? $vehicle->plate_number : 'Personal vehicle'],
                'issued_at' => $issuedAt, 'expires_at' => $issuedAt->addDays(7)]);
            $event = $this->transition($request, $actor, 'offered', $data['request_token'], $fingerprint, $offer, null, $issuedAt);
            $this->assertIntent($request, $offer->hub_id, $offer->vehicle_id, $offer->barangay);
            abort_unless(hash_equals($offer->source_hash, $this->sourceHash($this->sources($request, $offer->hub_id, $offer->vehicle_id))), 409,
                'Assignment sources changed while saving. Refresh before issuing an offer.');

            return $this->receipt($event);
        });
    }

    public function companyDecision(User $actor, mixed $requestId, string $action, array $input): array
    {
        $this->requireAvailable();
        abort_unless(in_array($action, ['rejected', 'cancelled'], true), 404);
        $data = $this->validate($input, ['expected_version' => ['required', $this->number()], 'request_token' => $this->token(),
            'reason' => ['required', 'string', new ApplicationText('notes', 5, 500)]], ['reason' => 500]);
        $source = $this->companyRequest($actor, $requestId);
        $fingerprint = $this->fingerprint(['action' => $action, 'request_id' => $source->id,
            'expected_version' => (int) $data['expected_version'], 'reason' => $data['reason']]);

        return DB::transaction(function () use ($actor, $source, $action, $data, $fingerprint) {
            [$company, $request] = $this->locked($source);
            abort_unless($company->user_id === $actor->id && $this->requests->companyOwner($actor), 403);
            if ($receipt = $this->replay($actor, $request, $data['request_token'], $fingerprint)) {
                return $receipt;
            }
            $this->current($request, (int) $data['expected_version'], [$action === 'rejected' ? 'pending' : 'offered']);

            return $this->receipt($this->transition($request, $actor, $action, $data['request_token'], $fingerprint,
                $request->currentOffer, $data['reason']));
        });
    }

    public function riderDecision(User $actor, mixed $requestId, string $action, array $input): array
    {
        $this->requireAvailable();
        abort_unless(in_array($action, ['accepted', 'declined', 'withdrawn'], true), 404);
        $rules = ['expected_version' => ['required', $this->number()], 'request_token' => $this->token()];
        if ($action === 'accepted') {
            $rules += ['offer_id' => ['required', $this->number()], 'terms_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
                'acknowledged' => ['required', 'boolean', 'accepted']];
        } else {
            $rules['reason'] = ['nullable', 'string', new ApplicationText('notes', 5, 500)];
        }
        $data = $this->validate($input, $rules, $action === 'accepted' ? [] : ['reason' => 500]);
        $rider = $this->requests->rider($actor);
        Validator::make(['request_id' => $requestId], ['request_id' => ['required', $this->number()]])->validate();
        $source = RiderCompanyRequest::whereKey($requestId)->where('rider_id', $rider->id)->firstOrFail();
        $intent = ['action' => $action, 'request_id' => $source->id, 'expected_version' => (int) $data['expected_version']];
        if ($action === 'accepted') {
            $intent += ['offer_id' => (int) $data['offer_id'], 'terms_hash' => $data['terms_hash'], 'acknowledged' => true];
        } elseif (($data['reason'] ?? null) !== null) {
            $intent['reason'] = $data['reason'];
        }
        $fingerprint = $this->fingerprint($intent);

        return DB::transaction(function () use ($rider, $source, $action, $data, $fingerprint) {
            [, $request] = $this->locked($source);
            $rider = $this->requests->rider($rider);
            if ($receipt = $this->replay($rider, $request, $data['request_token'], $fingerprint)) {
                return $receipt;
            }
            $this->current($request, (int) $data['expected_version'], $action === 'withdrawn' ? ['pending', 'offered'] : ['offered']);
            $offer = $request->currentOffer;
            if ($action !== 'accepted') {
                return $this->receipt($this->transition($request, $rider, $action, $data['request_token'], $fingerprint, $offer, $data['reason'] ?? null));
            }
            abort_unless($offer && $offer->id === (int) $data['offer_id'] && hash_equals($offer->terms_hash, $data['terms_hash']), 409,
                'The displayed offer changed. Read the current offer before accepting.');
            $this->assertCurrentOffer($request, $offer);
            $consent = RiderCompanyConsent::create(['offer_id' => $offer->id, 'rider_id' => $rider->id,
                'logistics_company_id' => $request->logistics_company_id, 'request_token' => $data['request_token'],
                'fingerprint' => $fingerprint, 'terms_hash' => $offer->terms_hash, 'source_hash' => $offer->source_hash,
                'acknowledged' => true, 'accepted_at' => now()]);
            try {
                app(LogisticsPlacementService::class)->placeCourier(User::findOrFail($offer->issuer_id), LogisticsHub::findOrFail($offer->hub_id),
                    $rider, $offer->vehicle_id, $offer->barangay, null, $consent);
            } catch (DomainException $exception) {
                abort(409, $exception->getMessage());
            }
            $event = $this->transition($request, $rider, 'accepted', $data['request_token'], $fingerprint, $offer, null, $consent->accepted_at);
            // Recheck authorization after every required writer, including the outbox.
            $this->requests->rider($rider);
            abort_unless($this->requests->companyOwner(User::findOrFail($offer->issuer_id)), 409, 'Company eligibility changed while accepting.');
            $profile = CourierProfile::where('user_id', $rider->id)->firstOrFail();
            try {
                app(LogisticsEligibilityService::class)->assertCourierScope($profile, $request->logistics_company_id, $offer->hub_id);
            } catch (DomainException $exception) {
                abort(409, $exception->getMessage());
            }
            $expected = $offer->sources;
            $expected['profile'] = [...$expected['profile'], 'logistics_company_id' => $request->logistics_company_id,
                'assigned_hub_id' => $offer->hub_id, 'vehicle_id' => $offer->vehicle_id, 'assigned_barangay' => $offer->barangay, 'is_available' => false];
            if ($expected['fleet'] !== null) {
                $expected['fleet']['assigned_driver_id'] = $rider->id;
            }
            $expected['placement_record_id'] = LogisticsPlacementRecord::where('rider_company_consent_id', $consent->id)->sole()->id;
            $currentSources = $this->sources($request, $offer->hub_id, $offer->vehicle_id);
            foreach (['profile' => $profile->id, 'fleet' => $offer->vehicle_id] as $kind => $id) {
                if ($id !== null) {
                    $changes = DB::table('rider_company_scope_changes')->where('source_type', $kind)->where('source_id', $id)
                        ->where('id', '>', $offer->sources['scope_versions'][$kind])->count();
                    abort_unless($changes === 1, 409, 'Assignment sources changed during placement. Refresh the current offer.');
                    $expected['scope_versions'][$kind] = $currentSources['scope_versions'][$kind];
                }
            }
            abort_unless(hash_equals($this->sourceHash($expected), $this->sourceHash($currentSources)), 409,
                'Assignment sources changed while accepting. Refresh the current offer.');
            $this->present($request->fresh());

            return $this->receipt($event);
        });
    }

    /** Only a retained, exact consent can authorize a first company association. */
    public function authorizePlacement(?RiderCompanyConsent $candidate, User $actor, LogisticsCompany $company, User $rider, LogisticsHub $hub, ?int $vehicleId, ?string $barangay): RiderCompanyConsent
    {
        $this->requireAvailable();
        $consent = $candidate?->exists ? RiderCompanyConsent::whereKey($candidate->id)->lockForUpdate()->first() : null;
        abort_unless($consent && $consent->acknowledged && $consent->rider_id === $rider->id && $consent->logistics_company_id === $company->id, 409,
            'A new company assignment needs the rider’s accepted offer. Use Rider requests in Network.');
        $offer = RiderCompanyOffer::findOrFail($consent->offer_id);
        $request = RiderCompanyRequest::whereKey($offer->rider_company_request_id)->lockForUpdate()->firstOrFail();
        abort_unless($request->rider_id === $rider->id && $request->logistics_company_id === $company->id
            && $request->status === 'offered' && $request->current_offer_id === $offer->id
            && $offer->issuer_id === $actor->id && $offer->hub_id === $hub->id && $offer->vehicle_id === $vehicleId
            && $offer->barangay === $barangay && hash_equals($consent->terms_hash, $offer->terms_hash)
            && hash_equals($consent->source_hash, $offer->source_hash)
            && $consent->accepted_at->greaterThanOrEqualTo($offer->issued_at) && $consent->accepted_at->lessThan($offer->expires_at)
            && $consent->accepted_at->lessThanOrEqualTo(now())
            && hash_equals($consent->fingerprint, $this->fingerprint(['action' => 'accepted', 'request_id' => $request->id,
                'expected_version' => $request->version, 'offer_id' => $offer->id, 'terms_hash' => $offer->terms_hash, 'acknowledged' => true]))
            && ! LogisticsPlacementRecord::where('rider_company_consent_id', $consent->id)->exists(), 409,
            'The retained consent does not authorize this assignment.');
        $this->assertCurrentOffer($request, $offer);

        return $consent;
    }

    private function companyRequest(User $actor, mixed $id): RiderCompanyRequest
    {
        abort_unless($this->requests->companyOwner($actor), 403);
        Validator::make(['request_id' => $id], ['request_id' => ['required', $this->number()]])->validate();

        return RiderCompanyRequest::whereKey($id)->whereHas('company', fn ($query) => $query->where('user_id', $actor->id))->firstOrFail();
    }

    private function locked(RiderCompanyRequest $source): array
    {
        $company = $this->requests->lock($source->logistics_company_id, $source->rider_id);
        LogisticsHub::where('logistics_company_id', $company->id)->orderBy('id')->lockForUpdate()->get();
        CourierProfile::where('user_id', $source->rider_id)->lockForUpdate()->first();
        LogisticsFleet::where('logistics_company_id', $company->id)->orderBy('id')->lockForUpdate()->get();
        $request = RiderCompanyRequest::whereKey($source->id)->lockForUpdate()->firstOrFail();
        $this->present($request);

        return [$company, $request];
    }

    private function current(RiderCompanyRequest $request, int $version, array $states): void
    {
        abort_unless($request->version === $version && in_array($request->status, $states, true), 409,
            'The request changed. Refresh its current history before trying again.');
        abort_if($request->status === 'offered' && $request->currentOffer?->expires_at->lessThanOrEqualTo(now()), 409,
            'This offer expired. Refresh the requests before applying again.');
    }

    private function assertIntent(RiderCompanyRequest $request, int $hubId, ?int $vehicleId, ?string $barangay): void
    {
        $company = LogisticsCompany::findOrFail($request->logistics_company_id);
        abort_unless($this->requests->companyOwner(User::findOrFail($company->user_id)) && $company->rider_intake_enabled
            && $this->requests->intakeRecorded($company), 409, 'The company is not accepting assignments.');
        $rider = $this->requests->rider(User::findOrFail($request->rider_id));
        abort_if($this->requests->applicationBlocker($rider, $rider->courierProfile) !== null, 409,
            'The rider is no longer available for a first company assignment.');
        $hub = LogisticsHub::eligible()->where('logistics_company_id', $company->id)->whereKey($hubId)->first();
        abort_unless($hub, 409, 'Choose an eligible facility in your company.');
        abort_if($barangay !== null && (! $hub->isBayanHub() || ! $hub->coversBarangay($barangay)), 409,
            'Choose a covered barangay at the selected Bayan Hub, or leave it unassigned.');
        if ($vehicleId !== null) {
            $vehicle = LogisticsFleet::ready()->whereKey($vehicleId)->where('logistics_company_id', $company->id)
                ->where('hub_id', $hubId)->whereNull('assigned_driver_id')->first();
            abort_unless($vehicle && ! CourierProfile::where('vehicle_id', $vehicleId)->exists(), 409,
                'That vehicle is unavailable at the chosen facility.');
        }
    }

    private function assertCurrentOffer(RiderCompanyRequest $request, RiderCompanyOffer $offer): void
    {
        $this->current($request, $request->version, ['offered']);
        $this->assertIntent($request, $offer->hub_id, $offer->vehicle_id, $offer->barangay);
        abort_unless(hash_equals($offer->source_hash, $this->sourceHash($this->sources($request, $offer->hub_id, $offer->vehicle_id))), 409,
            'Assignment details or eligibility changed. Ask the company to issue a new offer.');
    }

    private function sources(RiderCompanyRequest $request, int $hubId, ?int $vehicleId): array
    {
        $company = LogisticsCompany::findOrFail($request->logistics_company_id);
        $fields = ['id', 'role', 'status', 'kyc_status', 'kyc_reviewed_at', 'birthday', 'closed_at', 'identity_version', 'restriction_version', 'email_verified_at'];

        return ['company' => $company->only(['id', 'user_id', 'status', 'is_active', 'restriction_version', 'rider_intake_enabled', 'rider_intake_version']),
            'owner' => User::findOrFail($company->user_id)->only($fields),
            'rider' => User::findOrFail($request->rider_id)->only($fields),
            'profile' => CourierProfile::where('user_id', $request->rider_id)->firstOrFail()->only([
                'id', 'user_id', 'logistics_company_id', 'assigned_hub_id', 'assigned_barangay', 'vehicle_id',
                'vehicle_type', 'plate_number', 'license_number', 'or_cr_status', 'is_available']),
            'placement_record_id' => LogisticsPlacementRecord::where('user_id', $request->rider_id)->max('id'),
            'hub' => LogisticsHub::findOrFail($hubId)->only(['id', 'logistics_company_id', 'tier', 'is_active', 'restriction_version', 'coverage_barangays']),
            'fleet' => $vehicleId ? LogisticsFleet::findOrFail($vehicleId)->only(['id', 'logistics_company_id', 'hub_id', 'assigned_driver_id', 'vehicle_type', 'plate_number', 'status', 'restriction_version']) : null,
            'scope_versions' => ['owner' => $this->sourceVersion('account', $company->user_id), 'rider' => $this->sourceVersion('account', $request->rider_id),
                'company' => $this->sourceVersion('company', $company->id), 'hub' => $this->sourceVersion('hub', $hubId),
                'profile' => $this->sourceVersion('profile', CourierProfile::where('user_id', $request->rider_id)->value('id')),
                'fleet' => $vehicleId ? $this->sourceVersion('fleet', $vehicleId) : 0]];
    }

    private function sourceVersion(string $kind, int $id): int
    {
        return (int) DB::table('rider_company_scope_changes')->where('source_type', $kind)->where('source_id', $id)->max('id');
    }

    private function transition(RiderCompanyRequest $request, ?User $actor, string $action, string $token, string $fingerprint, ?RiderCompanyOffer $offer, ?string $reason = null, mixed $time = null): RiderCompanyRequestEvent
    {
        abort_if($request->version === PHP_INT_MAX, 409, 'Request history needs a reviewed repair before another decision.');
        $request->status = $action;
        $request->version++;
        $request->current_offer_id = $offer?->id;
        if ($action === 'withdrawn') {
            $request->withdrawn_at = $time ?? now();
        }
        $event = RiderCompanyRequestEvent::create(['rider_company_request_id' => $request->id, 'actor_id' => $actor?->id,
            'request_token' => $token, 'fingerprint' => $fingerprint, 'action' => $action,
            'request_version' => $request->version, 'result' => $this->requests->result($request),
            'offer_id' => $offer?->id, 'reason' => $reason, 'recorded_at' => $time ?? $request->withdrawn_at ?? now()]);
        $request->save();
        $request->unsetRelation('events')->unsetRelation('currentOffer');
        $this->notice($event);

        return $event;
    }

    private function replay(User $actor, RiderCompanyRequest $request, string $token, string $fingerprint): ?array
    {
        $event = RiderCompanyRequestEvent::where('actor_id', $actor->id)->where('request_token', $token)->first();
        if (! $event) {
            return null;
        }
        abort_unless($event->rider_company_request_id === $request->id && hash_equals($event->fingerprint, $fingerprint), 409,
            'This submission key already records a different action.');

        return $this->receipt($event, true);
    }

    private function receipt(RiderCompanyRequestEvent $event, bool $replayed = false): array
    {
        return [...$event->result, 'offer' => $event->offer_id ? $this->offerSummary(RiderCompanyOffer::findOrFail($event->offer_id)) : null,
            'recorded_at' => $event->recorded_at->toISOString(), 'replayed' => $replayed];
    }

    public function present(RiderCompanyRequest $request): array
    {
        $base = $this->requests->present($request);
        if (! $this->available()) {
            return $base;
        }
        $offers = $request->offers()->orderBy('version')->get();
        $events = $request->events->sortBy('request_version')->values();
        $issued = $events->where('action', 'offered')->values();
        $valid = $offers->count() === $issued->count();
        foreach ($offers as $index => $offer) {
            $event = $issued->get($index);
            $valid = $valid && $event && $event->offer_id === $offer->id && $event->actor_id === $offer->issuer_id
                && $event->recorded_at->equalTo($offer->issued_at) && $offer->version === $index + 1
                && $offer->expires_at->equalTo($offer->issued_at->addDays(7))
                && hash_equals($offer->terms_hash, hash('sha256', $offer->terms))
                && hash_equals($offer->source_hash, $this->sourceHash($offer->sources));
        }
        $valid = $valid && $request->current_offer_id === $offers->last()?->id;
        $currentOfferId = null;
        foreach ($events as $event) {
            if ($event->action === 'offered') {
                $currentOfferId = $event->offer_id;
            }
            $valid = $valid && $event->offer_id === $currentOfferId;
        }
        $consent = $request->current_offer_id ? RiderCompanyConsent::where('offer_id', $request->current_offer_id)->first() : null;
        if ($request->status === 'accepted') {
            $event = $events->last();
            $record = $consent ? LogisticsPlacementRecord::where('rider_company_consent_id', $consent->id)->first() : null;
            $offer = $offers->last();
            $valid = $valid && $offer && $consent && $record && $consent->acknowledged
                && $event->actor_id === $request->rider_id && $event->offer_id === $offer->id
                && $event->request_token === $consent->request_token && hash_equals($event->fingerprint, $consent->fingerprint)
                && $consent->rider_id === $request->rider_id && $consent->logistics_company_id === $request->logistics_company_id
                && $consent->accepted_at->equalTo($event->recorded_at) && $consent->accepted_at->lessThan($offer->expires_at)
                && hash_equals($consent->terms_hash, $offer->terms_hash) && hash_equals($consent->source_hash, $offer->source_hash)
                && $record->user_id === $request->rider_id && $record->actor_id === $offer->issuer_id
                && $record->logistics_company_id === $request->logistics_company_id && $record->kind === 'courier'
                && Arr::sortRecursive($record->after_state) === Arr::sortRecursive(['logistics_company_id' => $request->logistics_company_id,
                    'assigned_hub_id' => $offer->hub_id, 'vehicle_id' => $offer->vehicle_id, 'assigned_barangay' => $offer->barangay]);
        } else {
            $valid = $valid && $consent === null;
        }
        abort_unless($valid, 503, 'Recorded offer history is temporarily unavailable.');
        $current = $offers->last();
        $expired = $request->status === 'offered' && $current?->expires_at->lessThanOrEqualTo(now());
        $canAccept = false;
        if ($request->status === 'offered' && ! $expired) {
            try {
                $this->assertCurrentOffer($request, $current);
                $canAccept = true;
            } catch (HttpException $exception) {
                if (! in_array($exception->getStatusCode(), [403, 409], true)) {
                    throw $exception;
                }
            }
        }

        return [...$base, 'effective_status' => $expired ? 'expired' : $request->status, 'can_accept' => $canAccept,
            'offer' => $current ? $this->offerSummary($current) : null,
            'offers' => $offers->map(fn ($offer) => [...$this->offerSummary($offer),
                'state' => $offer->id !== $request->current_offer_id ? 'superseded' : ($expired ? 'expired' : $request->status)])->all(),
            'decisions' => $events->map(fn ($event) => ['action' => $event->action, 'reason' => $event->reason,
                'at' => $event->recorded_at->toISOString(), 'offer_id' => $event->offer_id ? (string) $event->offer_id : null])->all(),
            'accepted_at' => $request->status === 'accepted' ? $consent->accepted_at->toISOString() : null,
            'decision_token' => (string) Str::uuid()];
    }

    private function offerSummary(RiderCompanyOffer $offer): array
    {
        return ['id' => (string) $offer->id, 'reference' => $offer->reference, 'version' => $offer->version,
            'hub_id' => (string) $offer->hub_id, 'vehicle_id' => $offer->vehicle_id ? (string) $offer->vehicle_id : null,
            'barangay' => $offer->barangay, 'terms' => $offer->terms, 'terms_hash' => $offer->terms_hash,
            'assignment' => $offer->display, 'issued_at' => $offer->issued_at->toISOString(), 'expires_at' => $offer->expires_at->toISOString()];
    }

    public function companyChoices(LogisticsCompany $company, array $query, array $links): array
    {
        if (! $this->available()) {
            return ['offers_available' => false, 'hubs' => [], 'vehicles' => [], 'roster' => null];
        }
        $page = $query['rider_roster_page'] ?? 1;
        Validator::make(['rider_roster_page' => $page], ['rider_roster_page' => ['required', $this->number(), 'integer', 'min:1', 'max:1000000']])->validate();
        $riders = CourierProfile::where('logistics_company_id', $company->id)->with(['user', 'hub', 'vehicle'])->orderBy('id');
        $roster = (clone $riders)->paginate(15, ['*'], 'rider_roster_page', (int) $page);
        if ($page > $roster->lastPage()) {
            $roster = $riders->paginate(15, ['*'], 'rider_roster_page', $roster->lastPage());
        }
        $roster->appends([...$links, 'rider_request_page' => $query['rider_request_page'] ?? 1])->through(fn ($profile) => [
            'id' => (string) $profile->user_id, 'name' => $profile->user?->name, 'email' => $profile->user?->email,
            'hub_id' => $profile->assigned_hub_id ? (string) $profile->assigned_hub_id : null, 'hub' => $profile->hub?->name,
            'vehicle_id' => $profile->vehicle_id ? (string) $profile->vehicle_id : null,
            'vehicle' => $profile->vehicle?->plate_number ?? 'Personal vehicle', 'barangay' => $profile->assigned_barangay,
            'on_duty' => $profile->is_available, 'eligible' => CourierProfile::operational()->whereKey($profile->id)->exists(),
            'provenance' => LogisticsPlacementRecord::where('user_id', $profile->user_id)->whereNotNull('rider_company_consent_id')->exists() ? 'Accepted offer' : 'Existing assignment',
        ]);

        return ['offers_available' => true, 'roster' => $roster,
            'hubs' => LogisticsHub::eligible()->where('logistics_company_id', $company->id)->orderBy('name')->get()
                ->map(fn ($hub) => ['id' => (string) $hub->id, 'name' => $hub->name, 'tier' => $hub->tier,
                    'barangays' => $hub->isBayanHub() ? ($hub->coverage_barangays ?? []) : []]),
            'vehicles' => LogisticsFleet::ready()->where('logistics_company_id', $company->id)->whereNull('assigned_driver_id')
                ->whereNotIn('id', CourierProfile::whereNotNull('vehicle_id')->select('vehicle_id'))->orderBy('plate_number')->get()->map(fn ($vehicle) => [
                    'id' => (string) $vehicle->id, 'hub_id' => (string) $vehicle->hub_id, 'plate' => $vehicle->plate_number])];
    }

    public function expireForRider(User $rider): int
    {
        return $this->expireDue(100, $rider->id);
    }

    public function expireDue(int $limit = 100, ?int $riderId = null): int
    {
        if (! $this->available()) {
            return 0;
        }
        $sources = RiderCompanyRequest::where('status', 'offered')->whereHas('currentOffer', fn ($offer) => $offer->where('expires_at', '<=', now()->format('Y-m-d H:i:s.u')))
            ->when($riderId !== null, fn ($query) => $query->where('rider_id', $riderId))->orderBy('id')->limit(max(1, min(1000, $limit)))->get();
        $count = 0;
        foreach ($sources as $source) {
            $count += DB::transaction(function () use ($source) {
                [, $request] = $this->locked($source);
                if ($request->status !== 'offered' || ! $request->currentOffer->expires_at->lessThanOrEqualTo(now())) {
                    return 0;
                }
                $this->transition($request, null, 'expired', (string) Str::uuid(), $this->fingerprint(['expiry' => $request->current_offer_id]), $request->currentOffer);

                return 1;
            });
        }

        return $count;
    }

    public function notice(RiderCompanyRequestEvent $event): void
    {
        $request = RiderCompanyRequest::findOrFail($event->rider_company_request_id);
        $ownerId = LogisticsCompany::whereKey($request->logistics_company_id)->value('user_id');
        $title = match ($event->action) {
            'submitted' => 'A rider company request was submitted', 'offered' => 'A company assignment offer is ready',
            'accepted' => 'The rider accepted the company assignment', 'rejected' => 'The company request was declined',
            'declined' => 'The rider declined the assignment offer', 'cancelled' => 'The company withdrew its assignment offer',
            'withdrawn' => 'The rider withdrew the company request', 'expired' => 'The company assignment offer expired',
        };
        foreach ([$request->rider_id => 'rider-company-assignment', $ownerId => 'company-rider-requests'] as $id => $target) {
            if (! $id) {
                continue;
            }
            app(NotificationDeliveryService::class)->record((int) $id, 'governance-event', 'rider-company:'.$event->id,
                ['title' => $title, 'body' => 'Open the saved request for its current assignment details and next steps.',
                    'target' => $target, 'company_request_id' => $request->id]);
        }
    }

    private function sourceHash(array $sources): string
    {
        // PostgreSQL jsonb does not preserve object key order.
        return hash('sha256', json_encode(Arr::sortRecursive($sources), JSON_THROW_ON_ERROR));
    }

    private function requireAvailable(): void
    {
        abort_unless($this->available(), 503, 'Company assignment offers are temporarily unavailable.');
    }
}
