<?php

namespace App\Services\Courier;

use App\Models\CourierProfile;
use App\Models\LogisticsCompany;
use App\Models\RiderCompanyRequest;
use App\Rules\ApplicationText;
use App\Services\Logistics\CompanyRequestInput;
use App\Services\Logistics\LogisticsEligibilityService;
use App\Services\Logistics\RiderCompanyOfferService;
use App\Services\Logistics\RiderCompanyRequestService;
use App\Services\RiderAccountService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class RiderCompanyService
{
    use CompanyRequestInput;

    public function __construct(private readonly RiderCompanyRequestService $requests, private readonly RiderCompanyOfferService $offers,
        private readonly RiderCommandService $commands) {}

    public function companies(Request $request): array
    {
        $input = $this->validate($request->all(), $this->pages() + [
            'search' => ['nullable', 'string', new ApplicationText('notes', 1, 100)],
            'province' => ['nullable', 'string', new ApplicationText('notes', 1, 100)],
        ], ['search' => 100, 'province' => 100]);
        $query = $this->requests->directory();
        if (filled($input['search'] ?? null)) {
            $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($input['search']));
            $query->whereRaw("LOWER(logistics_companies.name) LIKE ? ESCAPE '!'", ['%'.$literal.'%']);
        }
        if (filled($input['province'] ?? null)) {
            $query->whereHas('hubs', fn ($hub) => $hub->eligible()->where('province', $input['province']));
        }

        return $this->page($query->orderBy('name')->orderBy('id'), $input, fn ($company) => $this->companySummary($company));
    }

    public function company(Request $request, string $id): array
    {
        $this->emptyRead($request);

        return $this->companySummary($this->requests->directory()->whereKey(RiderApiInput::id($id))->firstOrFail());
    }

    private function companySummary(LogisticsCompany $company): array
    {
        $areas = $company->hubs()->eligible()->select('province', 'city_municipality')->distinct()
            ->orderBy('province')->orderBy('city_municipality')->limit(51)->get();

        return ['id' => (string) $company->id, 'name' => $company->name, 'intake_version' => $company->rider_intake_version,
            'areas' => $areas->take(50)->map(fn ($area) => ['province' => $area->province, 'city' => $area->city_municipality])->all(),
            'areas_truncated' => $areas->count() > 50];
    }

    public function applications(Request $request): array
    {
        $input = $this->validate($request->all(), $this->pages());

        return $this->page(RiderCompanyRequest::where('rider_id', $request->user()->id)->with('events')->orderByDesc('id'),
            $input, fn ($application) => $this->summary($request, $application));
    }

    public function application(Request $request, string $id): array
    {
        $this->emptyRead($request);
        $application = $this->owned($request, $id);
        $data = $this->offers->present($application);

        return [...$this->summary($request, $application, $data), 'note' => $data['note'],
            'sharing_acknowledged' => $data['sharing_acknowledged'], 'shared_profile' => $data['shared_profile'],
            'offer' => $data['offer'], 'offers' => array_slice($data['offers'], -50), 'decisions' => array_slice($data['decisions'], -50),
            'history_limits' => ['offers_truncated' => count($data['offers']) > 50, 'decisions_truncated' => count($data['decisions']) > 50]];
    }

    private function summary(Request $request, RiderCompanyRequest $application, ?array $data = null): array
    {
        $data ??= $this->offers->present($application);
        $open = in_array($data['effective_status'], ['pending', 'offered'], true);
        $write = app(RiderAccountService::class)->hasCompanyAbility($request->user(), 'rider:company:write');

        return Arr::only($data, ['id', 'reference', 'company', 'status', 'effective_status', 'version', 'submitted_at', 'withdrawn_at', 'accepted_at'])
            + ['current_offer_id' => $data['offer']['id'] ?? null, 'actions' => [
                'accept' => $write && $data['can_accept'], 'decline' => $write && $data['effective_status'] === 'offered',
                'withdraw' => $write && $open]];
    }

    public function submit(Request $request): array
    {
        $input = $this->validate($request->all(), ['company_id' => ['required', $this->number()],
            'intake_version' => ['required', $this->number()], 'sharing_acknowledged' => ['required', 'boolean', 'accepted'],
            'note' => ['nullable', 'string', new ApplicationText('notes', 5, 500)]], ['note' => 500]);
        $this->trueBoolean($input, 'sharing_acknowledged');
        $input['company_id'] = (int) $input['company_id'];
        $input['intake_version'] = (int) $input['intake_version'];
        $input['note'] ??= null;

        return $this->commands->execute($request, 'company.submit', 'company-'.$input['company_id'], $input,
            fn () => $this->receipt($this->requests->submit($request->user(), [...$input, 'request_token' => $request->header('Idempotency-Key')])));
    }

    public function decide(Request $request, string $id, string $action): array
    {
        $id = RiderApiInput::id($id);
        $rules = ['expected_version' => ['required', $this->number()]];
        $rules += $action === 'accepted' ? ['offer_id' => ['required', $this->number()],
            'terms_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'], 'acknowledged' => ['required', 'boolean', 'accepted']]
            : ['reason' => ['nullable', 'string', new ApplicationText('notes', 5, 500)]];
        $input = $this->validate($request->all(), $rules, $action === 'accepted' ? [] : ['reason' => 500]);
        $input['expected_version'] = (int) $input['expected_version'];
        if ($action === 'accepted') {
            $this->trueBoolean($input, 'acknowledged');
            $input['offer_id'] = (int) $input['offer_id'];
        } else {
            $input['reason'] ??= null;
        }

        return $this->commands->execute($request, 'company.'.$action, 'company-request-'.$id, $input, function () use ($request, $id, $action, $input) {
            $this->owned($request, (string) $id);

            return $this->receipt($this->offers->riderDecision($request->user(), $id, $action,
                [...$input, 'request_token' => $request->header('Idempotency-Key')]));
        });
    }

    private function receipt(array $result): array
    {
        return Arr::only($result, ['id', 'reference', 'status', 'version', 'submitted_at', 'withdrawn_at'])
            + ['offer_id' => $result['offer']['id'] ?? null];
    }

    public function assignment(Request $request): array
    {
        $this->emptyRead($request);
        $rider = $this->requests->rider($request->user());
        $profile = CourierProfile::with(['company', 'hub', 'vehicle'])->where('user_id', $rider->id)->first();
        $blocker = $this->requests->applicationBlocker($rider, $profile);
        $open = RiderCompanyRequest::where('rider_id', $rider->id)->where(fn ($query) => $query->where('status', 'pending')
            ->orWhere(fn ($offered) => $offered->where('status', 'offered')
                ->whereHas('currentOffer', fn ($offer) => $offer->where('expires_at', '>', now()->format('Y-m-d H:i:s.u')))))->exists();
        $blocker ??= $open ? 'You already have an open company request. Review or withdraw it before choosing another company.' : null;
        $accepted = RiderCompanyRequest::where('rider_id', $rider->id)->where('status', 'accepted')
            ->where('logistics_company_id', $profile?->logistics_company_id)->first();
        $provenance = ['kind' => $profile?->logistics_company_id ? 'existing_assignment' : ($blocker && ! $open ? 'review_required' : 'unassigned'),
            'request_id' => null, 'offer_id' => null, 'accepted_at' => null];
        if ($accepted) {
            $evidence = $this->offers->present($accepted);
            $provenance = ['kind' => 'accepted_offer', 'request_id' => (string) $accepted->id,
                'offer_id' => $evidence['offer']['id'], 'accepted_at' => $evidence['accepted_at']];
        }

        return ['company' => $profile?->logistics_company_id ? ['id' => (string) $profile->logistics_company_id, 'name' => $profile->company?->name] : null,
            'hub' => $profile?->assigned_hub_id ? ['id' => (string) $profile->assigned_hub_id, 'name' => $profile->hub?->name] : null,
            'vehicle' => ['id' => $profile?->vehicle_id ? (string) $profile->vehicle_id : null, 'label' => $profile?->vehicle?->plate_number ?? 'Personal vehicle'],
            'barangay' => $profile?->assigned_barangay, 'on_duty' => (bool) $profile?->is_available, 'provenance' => $provenance,
            'eligibility' => ['operational' => app(LogisticsEligibilityService::class)->isOperational($profile)],
            'actions' => ['apply' => $blocker === null && app(RiderAccountService::class)->hasCompanyAbility($request->user(), 'rider:company:write')],
            'blocked_reason' => $blocker, 'sharing_summary' => $this->requests->profileSummary($rider, $profile),
            'observed_at' => RiderApiInput::time(now())];
    }

    private function owned(Request $request, string $id): RiderCompanyRequest
    {
        return RiderCompanyRequest::where('rider_id', $request->user()->id)->whereKey(RiderApiInput::id($id))->firstOrFail();
    }

    public function emptyRead(Request $request): void
    {
        $this->validate($request->all(), []);
    }

    private function pages(): array
    {
        return ['page' => ['sometimes', 'required', $this->number(), 'integer', 'min:1', 'max:10000'],
            'per_page' => ['sometimes', 'required', $this->number(), 'integer', 'min:1', 'max:50']];
    }

    private function page(Builder $query, array $input, callable $map): array
    {
        $rows = $query->paginate((int) ($input['per_page'] ?? 20), ['*'], 'page', (int) ($input['page'] ?? 1));

        return ['items' => $rows->getCollection()->map($map)->all(), 'pagination' => [
            'page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(), 'last_page' => $rows->lastPage()]];
    }

    private function trueBoolean(array $input, string $field): void
    {
        if ($input[$field] !== true) {
            RiderApiInput::error('VALIDATION_FAILED', 'Confirm with a true JSON boolean.', 422, [$field => ['A true JSON boolean is required.']]);
        }
    }
}
