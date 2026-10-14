<?php

namespace App\Services\Logistics;

use App\Models\CourierProfile;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\RiderCompanyIntakeEvent;
use App\Models\RiderCompanyRequest;
use App\Models\RiderCompanyRequestEvent;
use App\Models\User;
use App\Rules\ApplicationText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Normalizer;

class RiderCompanyRequestService
{
    public function available(): bool
    {
        return Schema::hasColumn('logistics_companies', 'rider_intake_version')
            && Schema::hasColumn('logistics_companies', 'rider_intake_enabled')
            && Schema::hasTable('rider_company_requests') && Schema::hasTable('rider_company_request_events')
            && Schema::hasTable('rider_company_intake_events');
    }

    public function riderWorkspace(User $actor, array $query = []): array
    {
        $rider = $this->rider($actor);
        if (! $this->available()) {
            return ['available' => false];
        }
        $filters = $this->filters($query);
        $profile = $rider->courierProfile;
        $reason = $this->applicationBlocker($rider, $profile);
        $pending = RiderCompanyRequest::where('rider_id', $rider->id)->where('status', 'pending')->exists();
        $reason ??= $pending ? 'You already have a pending request. You can withdraw it before choosing another company.' : null;
        $directory = $this->directory();
        $provinces = LogisticsHub::eligible()->whereIn('logistics_company_id', (clone $directory)->select('logistics_companies.id'))
            ->whereNotNull('province')->where('province', '!=', '')->distinct()->orderBy('province')->pluck('province');
        if ($filters['company_province'] !== '' && ! $provinces->contains($filters['company_province'])) {
            if (! LogisticsHub::where('province', $filters['company_province'])->exists()) {
                throw ValidationException::withMessages(['company_province' => 'Choose a recorded service province.']);
            }
            // A company may pause while a rider is reading a filtered history URL.
            $provinces->push($filters['company_province']);
            $provinces = $provinces->sort()->values();
        }
        $companies = $reason === null ? $directory : $directory->whereKey(0);
        if ($filters['company_search'] !== '') {
            $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($filters['company_search']));
            $companies->whereRaw("LOWER(logistics_companies.name) LIKE ? ESCAPE '!'", ['%'.$literal.'%']);
        }
        if ($filters['company_province'] !== '') {
            $companies->whereHas('hubs', fn ($hub) => $hub->eligible()->where('province', $filters['company_province']));
        }
        $companies->with(['hubs' => fn ($hub) => $hub->eligible()->orderBy('province')->orderBy('city_municipality')->orderBy('id')
            ->select('id', 'logistics_company_id', 'province', 'city_municipality')]);
        $links = ['tab' => 'assignment', ...$filters];
        $companyPage = $this->paginate($companies->orderBy('name')->orderBy('id'), 'company_page', $filters['company_page'], $links)
            ->through(fn (LogisticsCompany $company) => $this->companySummary($company));
        $history = $this->paginate(RiderCompanyRequest::where('rider_id', $rider->id)->with('events')->orderByDesc('id'),
            'request_page', $filters['request_page'], $links)->through(fn ($request) => [
                ...$this->present($request), 'can_withdraw' => $request->status === 'pending', 'withdrawal_token' => (string) Str::uuid(),
            ]);

        return ['available' => true, 'can_apply' => $reason === null, 'blocked_reason' => $reason,
            'filters' => $filters, 'provinces' => $provinces, 'companies' => $companyPage, 'requests' => $history,
            'sharing_summary' => $this->profileSummary($rider, $profile), 'request_token' => (string) Str::uuid()];
    }

    public function companyWorkspace(User $actor, array $query = []): ?array
    {
        if (! $this->companyOwner($actor)) {
            return null;
        }
        if (! $this->available()) {
            return ['available' => false];
        }
        $company = LogisticsCompany::eligible()->where('user_id', $actor->id)->firstOrFail();
        $page = $query['rider_request_page'] ?? 1;
        Validator::make(['rider_request_page' => $page], ['rider_request_page' => ['required', 'integer', 'min:1', 'max:1000000', $this->number()]])->validate();
        $hubId = $query['hub_id'] ?? null;
        $links = (is_int($hubId) || is_string($hubId)) && preg_match('/\A[1-9][0-9]*\z/', (string) $hubId) ? ['hub_id' => $hubId] : [];
        $pending = $this->paginate(RiderCompanyRequest::where('logistics_company_id', $company->id)->where('status', 'pending')
            ->with(['events', 'rider.courierProfile'])->orderByDesc('id'), 'rider_request_page', (int) $page, $links)
            ->through(fn ($request) => [...$this->present($request), 'rider_eligible' => $request->rider !== null
                && $request->rider->isEligibleCourier() && $request->rider->email_verified_at !== null
                && $this->applicationBlocker($request->rider, $request->rider->courierProfile) === null]);

        return ['available' => true, 'enabled' => (bool) $company->rider_intake_enabled, 'version' => $company->rider_intake_version,
            'history_available' => $this->intakeRecorded($company), 'has_eligible_hub' => $company->hubs()->eligible()->exists(),
            'request_token' => (string) Str::uuid(), 'requests' => $pending];
    }

    public function changeIntake(User $actor, array $input): array
    {
        $this->requireAvailable();
        $data = $this->validate($input, ['enabled' => ['required', 'boolean'], 'expected_version' => ['required', $this->number(true)],
            'request_token' => $this->token()]);
        $owned = LogisticsCompany::eligible()->where('user_id', $actor->id)->first();
        abort_unless($owned && $this->companyOwner($actor), 403);
        $fingerprint = $this->fingerprint(['action' => 'intake', 'company_id' => $owned->id,
            'enabled' => (bool) $data['enabled'], 'expected_version' => (int) $data['expected_version']]);

        return DB::transaction(function () use ($actor, $owned, $data, $fingerprint) {
            $company = $this->lock($owned->id, $actor->id);
            abort_unless($company->user_id === $actor->id && $this->companyOwner($actor), 403);
            $event = RiderCompanyIntakeEvent::where('actor_id', $actor->id)->where('request_token', $data['request_token'])->first();
            if ($event) {
                abort_unless(hash_equals($event->fingerprint, $fingerprint), 409, 'This submission key already records another intake decision.');

                return ['enabled' => $event->after_enabled, 'version' => $event->after_version, 'replayed' => true];
            }
            abort_unless($company->rider_intake_version === (int) $data['expected_version'] && $this->intakeRecorded($company), 409,
                'Company intake changed. Refresh its current settings before saving.');
            abort_if($company->rider_intake_version === PHP_INT_MAX, 409, 'Company intake needs a reviewed repair before another change.');
            $after = $company->rider_intake_version + 1;
            $event = RiderCompanyIntakeEvent::create(['logistics_company_id' => $company->id, 'actor_id' => $actor->id,
                'request_token' => $data['request_token'], 'fingerprint' => $fingerprint,
                'before_enabled' => $company->rider_intake_enabled, 'after_enabled' => (bool) $data['enabled'],
                'before_version' => $company->rider_intake_version, 'after_version' => $after, 'recorded_at' => now()]);
            $company->forceFill(['rider_intake_enabled' => $event->after_enabled, 'rider_intake_version' => $after])->save();
            abort_unless($this->companyOwner($actor), 403);

            return ['enabled' => $event->after_enabled, 'version' => $after, 'replayed' => false];
        });
    }

    public function submit(User $actor, array $input): array
    {
        $this->requireAvailable();
        $data = $this->validate($input, ['company_id' => ['required', $this->number()], 'intake_version' => ['required', $this->number()],
            'sharing_acknowledged' => ['required', 'boolean', 'accepted'], 'note' => ['nullable', 'string', new ApplicationText('notes', 5, 500)],
            'request_token' => $this->token()], ['note' => 500]);
        $fingerprint = $this->fingerprint(['action' => 'submitted', 'company_id' => (int) $data['company_id'],
            'intake_version' => (int) $data['intake_version'], 'sharing_acknowledged' => true, 'note' => $data['note'] ?? null]);
        $this->rider($actor);

        return DB::transaction(function () use ($actor, $data, $fingerprint) {
            $company = $this->lock((int) $data['company_id'], $actor->id);
            $rider = $this->rider($actor);
            if ($replay = $this->replay($rider, $data['request_token'], $fingerprint)) {
                return $replay;
            }
            LogisticsHub::where('logistics_company_id', $company->id)->orderBy('id')->lockForUpdate()->get();
            $profile = CourierProfile::where('user_id', $rider->id)->lockForUpdate()->first();
            abort_if($reason = $this->applicationBlocker($rider, $profile), 409, $reason ?? 'Rider application is unavailable.');
            abort_unless($this->directory()->whereKey($company->id)->exists(), 409, 'This company is not currently accepting rider requests.');
            abort_unless($company->rider_intake_version === (int) $data['intake_version'], 409, 'Company intake changed. Refresh the company before applying.');
            abort_if(RiderCompanyRequest::where('rider_id', $rider->id)->where('status', 'pending')->exists(), 409,
                'You already have a pending request. Withdraw it before choosing another company.');
            $request = RiderCompanyRequest::create(['reference' => 'RC-'.Str::ulid(), 'logistics_company_id' => $company->id,
                'rider_id' => $rider->id, 'request_token' => $data['request_token'], 'submission_fingerprint' => $fingerprint,
                'intake_version' => $company->rider_intake_version, 'company_snapshot' => ['name' => $company->name],
                'profile_snapshot' => $this->profileSummary($rider, $profile), 'note' => $data['note'] ?? null,
                'sharing_acknowledged' => true, 'status' => 'pending', 'version' => 1, 'submitted_at' => now()]);
            $result = $this->result($request);
            RiderCompanyRequestEvent::create(['rider_company_request_id' => $request->id, 'actor_id' => $rider->id,
                'request_token' => $data['request_token'], 'fingerprint' => $fingerprint, 'action' => 'submitted',
                'request_version' => 1, 'result' => $result, 'recorded_at' => $request->submitted_at]);
            $rider = $this->rider($rider);
            abort_if($this->applicationBlocker($rider, $profile?->fresh()) !== null, 409, 'Rider eligibility changed while saving the request.');
            abort_unless($this->directory()->whereKey($company->id)->exists(), 409, 'Company intake is no longer available.');
            abort_unless(LogisticsCompany::whereKey($company->id)->value('rider_intake_version') === (int) $data['intake_version'], 409,
                'Company intake changed while saving the request.');
            $this->present($request);

            return [...$result, 'replayed' => false];
        });
    }

    public function withdraw(User $actor, mixed $requestId, array $input): array
    {
        $this->requireAvailable();
        $data = $this->validate($input, ['expected_version' => ['required', $this->number()], 'request_token' => $this->token()]);
        Validator::make(['request_id' => $requestId], ['request_id' => ['required', $this->number()]])->validate();
        $rider = $this->rider($actor);
        $source = RiderCompanyRequest::whereKey($requestId)->where('rider_id', $rider->id)->firstOrFail();
        $fingerprint = $this->fingerprint(['action' => 'withdrawn', 'request_id' => (int) $requestId, 'expected_version' => (int) $data['expected_version']]);

        return DB::transaction(function () use ($rider, $source, $data, $fingerprint) {
            $this->lock($source->logistics_company_id, $rider->id);
            $rider = $this->rider($rider);
            if ($replay = $this->replay($rider, $data['request_token'], $fingerprint)) {
                return $replay;
            }
            $request = RiderCompanyRequest::whereKey($source->id)->where('rider_id', $rider->id)->lockForUpdate()->firstOrFail();
            $this->present($request);
            abort_unless($request->status === 'pending' && $request->version === (int) $data['expected_version'], 409, 'This request changed. Refresh its current status before withdrawing.');
            $time = now();
            $request->status = 'withdrawn';
            $request->version++;
            $request->withdrawn_at = $time;
            $result = $this->result($request);
            RiderCompanyRequestEvent::create(['rider_company_request_id' => $request->id, 'actor_id' => $rider->id,
                'request_token' => $data['request_token'], 'fingerprint' => $fingerprint, 'action' => 'withdrawn',
                'request_version' => $request->version, 'result' => $result, 'recorded_at' => $time]);
            $request->save();
            $this->rider($rider);
            $this->present($request->fresh());

            return [...$result, 'replayed' => false];
        });
    }

    public function present(RiderCompanyRequest $request): array
    {
        $request->loadMissing('events');
        $created = $request->events->firstWhere('action', 'submitted');
        $latest = $request->events->firstWhere('request_version', $request->version);
        $original = $this->result($request);
        $original['status'] = 'pending';
        $original['version'] = 1;
        $original['withdrawn_at'] = null;
        abort_unless($created && $latest && $created->actor_id === $request->rider_id && $created->request_version === 1
            && $created->request_token === $request->request_token && hash_equals($created->fingerprint, $request->submission_fingerprint)
            && $created->recorded_at->equalTo($request->submitted_at)
            && Arr::sortRecursive($created->result) === Arr::sortRecursive($original)
            && $latest->actor_id === $request->rider_id
            && $latest->action === ($request->status === 'pending' ? 'submitted' : 'withdrawn')
            && $request->version === ($request->status === 'pending' ? 1 : 2)
            && $request->events->count() === $request->version
            && $latest->recorded_at->equalTo($request->withdrawn_at ?? $request->submitted_at)
            && Arr::sortRecursive($latest->result) === Arr::sortRecursive($this->result($request)),
            503, 'Recorded request history is temporarily unavailable.');

        return $this->result($request);
    }

    private function result(RiderCompanyRequest $request): array
    {
        return ['id' => (string) $request->id, 'reference' => $request->reference,
            'company' => ['id' => (string) $request->logistics_company_id, 'name' => $request->company_snapshot['name']],
            'status' => $request->status, 'version' => $request->version, 'note' => $request->note,
            'sharing_acknowledged' => $request->sharing_acknowledged, 'shared_profile' => array_intersect_key($request->profile_snapshot,
                array_fill_keys(['name', 'email', 'phone', 'city', 'province', 'vehicle_type', 'approval_status'], true)),
            'submitted_at' => $request->submitted_at->toISOString(), 'withdrawn_at' => $request->withdrawn_at?->toISOString()];
    }

    private function profileSummary(User $rider, ?CourierProfile $profile): array
    {
        return ['name' => $rider->name, 'email' => $rider->email, 'phone' => $rider->phone,
            'city' => $rider->city, 'province' => $rider->province, 'vehicle_type' => $profile?->vehicle_type,
            'approval_status' => $rider->kyc_status];
    }

    private function companySummary(LogisticsCompany $company): array
    {
        $logo = $company->logo;
        if (! is_string($logo) || preg_match('/(?:kyc_documents|delivery-proofs|settlement-receipts|verification-document|\.\.|[\p{C}])/iu', $logo)
            || ! preg_match('~\A(?:/(?:images|storage)/[A-Za-z0-9_./-]+|https?://[^/?#\s@]+/[A-Za-z0-9_./-]+)\z~', $logo)) {
            $logo = null;
        }

        return ['id' => (string) $company->id, 'name' => $company->name, 'logo' => $logo,
            'intake_version' => $company->rider_intake_version, 'areas' => $company->hubs->map(fn ($hub) => [
                'province' => $hub->province, 'city' => $hub->city_municipality,
            ])->unique(fn ($area) => json_encode($area))->values()->all()];
    }

    private function directory(): Builder
    {
        return LogisticsCompany::eligible()->where('rider_intake_enabled', true)->where('rider_intake_version', '>', 0)
            ->whereHas('user', fn ($owner) => $owner->whereNotNull('email_verified_at'))
            ->whereHas('hubs', fn ($hub) => $hub->eligible())
            ->whereHas('riderIntakeEvents', fn ($event) => $event->where('after_enabled', true)
                ->whereColumn('after_version', 'logistics_companies.rider_intake_version')
                ->whereColumn('actor_id', 'logistics_companies.user_id'));
    }

    private function intakeRecorded(LogisticsCompany $company): bool
    {
        if ($company->rider_intake_version === 0) {
            return ! $company->rider_intake_enabled;
        }

        return RiderCompanyIntakeEvent::where('logistics_company_id', $company->id)->where('actor_id', $company->user_id)
            ->where('after_version', $company->rider_intake_version)->where('after_enabled', $company->rider_intake_enabled)->exists();
    }

    private function rider(User $actor): User
    {
        $rider = User::find($actor->id);
        abort_unless($rider?->isEligibleCourier() && $rider->email_verified_at !== null, 403);

        return $rider;
    }

    private function companyOwner(User $actor): bool
    {
        $owner = User::find($actor->id);

        return $owner?->isLogistics() && $owner->canAccessPortal() && $owner->email_verified_at !== null
            && app(LogisticsEligibilityService::class)->isCompanyAdministrator($owner);
    }

    private function applicationBlocker(User $rider, ?CourierProfile $profile): ?string
    {
        if ($profile?->logistics_company_id !== null) {
            return 'Your current company manages your working assignment. Requests to change companies are not available.';
        }
        if (! $profile || $profile->assigned_hub_id !== null || $profile->vehicle_id !== null || $rider->birthday === null
            || ! filled($profile->vehicle_type) || ! filled($profile->plate_number) || ! filled($profile->license_number)) {
            return 'Complete your reviewed rider and vehicle information before requesting a company assignment.';
        }

        return null;
    }

    private function lock(int $companyId, int $actorId): LogisticsCompany
    {
        $owner = LogisticsCompany::whereKey($companyId)->value('user_id');
        abort_if($owner === null, 404);
        User::whereIn('id', collect([$actorId, $owner])->unique()->sort()->values())->orderBy('id')->lockForUpdate()->get();
        $company = LogisticsCompany::whereKey($companyId)->lockForUpdate()->firstOrFail();
        abort_unless($company->user_id === $owner, 409, 'Company ownership changed. Refresh before trying again.');

        return $company;
    }

    private function replay(User $rider, string $key, string $fingerprint): ?array
    {
        $event = RiderCompanyRequestEvent::where('actor_id', $rider->id)->where('request_token', $key)->first();
        if (! $event) {
            return null;
        }
        abort_unless(hash_equals($event->fingerprint, $fingerprint), 409, 'This submission key already records another request or action.');
        $request = RiderCompanyRequest::whereKey($event->rider_company_request_id)->where('rider_id', $rider->id)->first();
        abort_unless($request && ($event->result['id'] ?? null) === (string) $request->id, 503, 'Recorded request history is temporarily unavailable.');
        $this->present($request);
        $result = $this->result($request);
        if ($event->action === 'submitted') {
            $result['status'] = 'pending';
            $result['version'] = 1;
            $result['withdrawn_at'] = null;
        }

        return [...$result, 'replayed' => true];
    }

    private function filters(array $input): array
    {
        $rules = ['company_search' => ['nullable', 'string', new ApplicationText('notes', 1, 100)],
            'company_province' => ['nullable', 'string', new ApplicationText('notes', 1, 100)],
            'company_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:1000000', $this->number()],
            'request_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:1000000', $this->number()]];
        $data = $this->validate(array_intersect_key($input, $rules), $rules, ['company_search' => 100, 'company_province' => 100]);

        return ['company_search' => $data['company_search'] ?? '', 'company_province' => $data['company_province'] ?? '',
            'company_page' => (int) ($data['company_page'] ?? 1), 'request_page' => (int) ($data['request_page'] ?? 1)];
    }

    private function validate(array $input, array $rules, array $text = []): array
    {
        $unexpected = array_diff_key($input, $rules);
        if ($unexpected) {
            throw ValidationException::withMessages(array_fill_keys(array_keys($unexpected), 'This field cannot be set through company requests.'));
        }
        foreach ($text as $field => $maximum) {
            if (! array_key_exists($field, $input) || $input[$field] === null) {
                continue;
            }
            Validator::make([$field => $input[$field]], [$field => ['bail', 'string', 'max:'.$maximum]])->validate();
            $value = $input[$field];
            if (! mb_check_encoding($value, 'UTF-8')) {
                throw ValidationException::withMessages([$field => 'Use readable plain text.']);
            }
            if (preg_match('/\A[ \r\n]*\z/', $value)) {
                $input[$field] = null;

                continue;
            }
            Validator::make([$field => $value], [$field => [new ApplicationText('notes', 1, $maximum)]])->validate();
            $normalized = Normalizer::normalize($value, Normalizer::FORM_KC);
            if ($normalized === false) {
                throw ValidationException::withMessages([$field => 'Use readable plain text.']);
            }
            $normalized = trim($normalized, " \r\n");
            $input[$field] = $normalized === '' ? null : $normalized;
        }

        return Validator::make($input, $rules)->validate();
    }

    private function number(bool $zero = false): \Closure
    {
        return function ($attribute, $value, $fail) use ($zero) {
            if ((! is_string($value) && ! is_int($value)) || ! preg_match($zero ? '/\A(?:0|[1-9][0-9]*)\z/' : '/\A[1-9][0-9]*\z/', (string) $value)
                || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $zero ? 0 : 1, 'max_range' => PHP_INT_MAX]]) === false) {
                $fail('Use a valid whole number for the :attribute.');
            }
        };
    }

    private function token(): array
    {
        return ['bail', 'required', 'string', 'uuid', 'regex:/\A[a-f0-9-]{36}\z/'];
    }

    private function fingerprint(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function paginate(Builder $query, string $name, int $page, array $filters): LengthAwarePaginator
    {
        $result = (clone $query)->paginate(15, ['*'], $name, $page);
        if ($page > $result->lastPage()) {
            $result = (clone $query)->paginate(15, ['*'], $name, $result->lastPage());
        }

        return $result->appends($filters);
    }

    private function requireAvailable(): void
    {
        abort_unless($this->available(), 503, 'Company requests are temporarily unavailable.');
    }
}
