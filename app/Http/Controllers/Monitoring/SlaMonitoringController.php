<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Concerns\EncryptsSensitiveHistory;
use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\HubPickupPause;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\MissingParcelInvestigation;
use App\Models\ShipmentFinancialPlan;
use App\Models\SlaClock;
use App\Models\SlaPause;
use App\Models\User;
use App\Services\Logistics\DispatchRecoveryService;
use App\Services\Logistics\LogisticsEligibilityService;
use App\Services\Logistics\MissingParcelInvestigationService;
use App\Services\Monitoring\EtaCalculationService;
use App\Services\Monitoring\SlaPauseService;
use App\Services\Monitoring\StateMonitoringService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class SlaMonitoringController extends Controller
{
    use EncryptsSensitiveHistory;

    public function __construct(
        private readonly LogisticsEligibilityService $eligibility,
    ) {}

    public function index(
        Request $request,
        DispatchRecoveryService $dispatchRecovery,
        EtaCalculationService $etaService,
    ): InertiaResponse|JsonResponse {
        [$user, $isAdmin, $isCompanyManager, $accessibleHubs, $companyId, $hubId] = $this->resolveScope($request);

        $accessibleHubIds = $accessibleHubs->pluck('id')->all();

        $pauses = Schema::hasTable('sla_pauses')
            ? SlaPause::query()
                ->with(['company:id,name,code', 'hub:id,name,code', 'createdBy:id,name', 'endedBy:id,name'])
                ->when(! $isAdmin, function ($q) use ($companyId, $accessibleHubIds): void {
                    $q->where(function ($sub) use ($companyId, $accessibleHubIds): void {
                        $sub->where('scope_type', 'platform');
                        if ($companyId) {
                            $sub->orWhere('logistics_company_id', $companyId);
                        }
                        if ($accessibleHubIds !== []) {
                            $sub->orWhereIn('logistics_hub_id', $accessibleHubIds);
                        }
                    });
                })
                ->when($companyId && $isAdmin, fn ($q) => $q->where(fn ($sub) => $sub->where('scope_type', 'platform')->orWhere('logistics_company_id', $companyId)))
                ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
                ->orderByDesc('starts_at')
                ->limit(40)
                ->get()
            : collect();

        $clocks = Schema::hasTable('sla_clocks')
            ? SlaClock::query()
                ->whereIn('status', ['running', 'paused'])
                ->when(! $isAdmin && $companyId, fn ($q) => $q->where('logistics_company_id', $companyId))
                ->when(! $isAdmin && ! $companyId && $accessibleHubIds !== [], fn ($q) => $q->whereIn('hub_id', $accessibleHubIds))
                ->when($isAdmin && $companyId, fn ($q) => $q->where('logistics_company_id', $companyId))
                ->when($hubId, fn ($q) => $q->where('hub_id', $hubId))
                ->with([
                    'delivery:id,tracking_number,status,current_hub_id,origin_bayan_hub_id,destination_bayan_hub_id,estimated_delivery_min_at,estimated_delivery_max_at,eta_basis',
                    'order:id,order_number,status',
                    'hub:id,name,code,tier',
                    'company:id,name,code',
                    'activePause:id,reference,title,reason_code',
                ])
                ->orderBy('breach_at')
                ->limit(80)
                ->get()
                ->map(fn (SlaClock $clock) => [
                    'id' => $clock->id,
                    'subject_type' => $clock->subject_type,
                    'subject_id' => $clock->subject_id,
                    'clock_kind' => $clock->clock_kind,
                    'status' => $clock->status,
                    'pause_reason' => $clock->pause_reason,
                    'started_at' => $clock->started_at?->toIso8601String(),
                    'warning_at' => $clock->warning_at?->toIso8601String(),
                    'breach_at' => $clock->breach_at?->toIso8601String(),
                    'critical_at' => $clock->critical_at?->toIso8601String(),
                    'paused_seconds' => (int) $clock->paused_seconds,
                    'is_breached' => $clock->status === 'running' && $clock->breach_at && $clock->breach_at->lessThanOrEqualTo(now()),
                    'order' => $clock->order?->only(['id', 'order_number', 'status']),
                    'delivery' => $clock->delivery ? [
                        'id' => $clock->delivery->id,
                        'tracking_number' => $clock->delivery->tracking_number,
                        'status' => $clock->delivery->status,
                        'eta' => $etaService->present($clock->delivery, $clock->order, false),
                    ] : null,
                    'hub' => $clock->hub?->only(['id', 'name', 'code', 'tier']),
                    'company' => $clock->company?->only(['id', 'name', 'code']),
                    'active_pause' => $clock->activePause?->only(['id', 'reference', 'title', 'reason_code']),
                ])
                ->values()
            : collect();

        $investigations = Schema::hasTable('missing_parcel_investigations')
            ? MissingParcelInvestigation::query()
                ->when(! $isAdmin && $companyId, fn ($q) => $q->where('logistics_company_id', $companyId))
                ->when(! $isAdmin && ! $companyId && $accessibleHubIds !== [], fn ($q) => $q->whereIn('hub_id', $accessibleHubIds))
                ->when($isAdmin && $companyId, fn ($q) => $q->where('logistics_company_id', $companyId))
                ->when($hubId, fn ($q) => $q->where('hub_id', $hubId))
                ->with([
                    'delivery:id,tracking_number,status,current_hub_id,origin_bayan_hub_id,destination_bayan_hub_id',
                    'order:id,order_number,status',
                    'hub:id,name,code',
                    'company:id,name,code',
                    'investigator:id,name',
                    'openedBy:id,name',
                    'resolvedBy:id,name',
                    'events.actor:id,name',
                ])
                ->orderByRaw("CASE WHEN status = 'investigating' THEN 0 ELSE 1 END")
                ->orderByDesc('opened_at')
                ->limit(60)
                ->get()
            : collect();

        $companies = $isAdmin
            ? LogisticsCompany::eligible()->orderBy('name')->get(['id', 'name', 'code'])
            : $this->eligibility->managedCompanies($user)->orderBy('name')->get(['id', 'name', 'code']);

        $investigatorIds = collect();
        if ($isAdmin) {
            $investigatorIds = $investigatorIds->merge(
                User::query()->where('role', 'admin')->where('status', 'active')->whereNull('closed_at')->limit(20)->pluck('id')
            );
        }
        $targetCompanyId = $companyId ?? ($companies->first()?->id);
        if ($targetCompanyId) {
            $investigatorIds = $investigatorIds->merge($this->eligibility->companyOperatorIds((int) $targetCompanyId)->take(30));
        }

        $investigators = $investigatorIds->isNotEmpty()
            ? User::query()->whereIn('id', $investigatorIds->unique()->values())->orderBy('name')->get(['id', 'name', 'role'])
            : collect();

        $financialPlans = $isAdmin
            ? ShipmentFinancialPlan::query()
                ->where('kind', 'transit_loss')
                ->latest('id')
                ->limit(40)
                ->get(['id', 'reference', 'order_id', 'buyer_return_case_id', 'created_at'])
            : collect();

        $telemetry = $dispatchRecovery->telemetry($companyId, $hubId);

        $openDeliveries = Delivery::query()
            ->when($companyId, fn ($q) => $q->where('logistics_company_id', $companyId))
            ->when(! $companyId && $accessibleHubIds !== [], fn ($q) => $q->where(fn ($sub) => $sub->whereIn('current_hub_id', $accessibleHubIds)->orWhereIn('origin_bayan_hub_id', $accessibleHubIds)))
            ->whereNotIn('status', ['delivered', 'customer_collected', 'completed', 'cancelled', 'returned'])
            ->latest('id')
            ->limit(40)
            ->get(['id', 'tracking_number', 'status', 'current_hub_id', 'origin_bayan_hub_id', 'destination_bayan_hub_id']);

        $data = [
            'scope' => [
                'is_admin' => $isAdmin,
                'is_company_manager' => $isCompanyManager,
                'company_id' => $companyId,
                'hub_id' => $hubId,
                'base_path' => $isAdmin ? '/admin' : '/hub',
            ],
            'pauses' => $pauses,
            'clocks' => $clocks,
            'telemetry' => $telemetry,
            'investigations' => $investigations,
            'companies' => $companies,
            'hubs' => $accessibleHubs->map(fn (LogisticsHub $h) => [
                'id' => $h->id,
                'name' => $h->name,
                'code' => $h->code,
                'tier' => $h->tier,
                'province' => $h->province,
                'city_municipality' => $h->city_municipality,
                'logistics_company_id' => $h->logistics_company_id,
            ])->values(),
            'investigators' => $investigators,
            'financialPlans' => $financialPlans,
            'openDeliveries' => $openDeliveries,
            'constants' => [
                'scopes' => SlaPause::SCOPES,
                'reasons' => SlaPause::REASONS,
            ],
        ];

        $this->encryptSensitiveHistory($request);

        return ($request->expectsJson() && ! $request->header('X-Inertia'))
            ? response()->json($data)->header('Cache-Control', 'private, no-store')
            : Inertia::render('Governance/Monitoring', $data);
    }

    public function storePause(Request $request, SlaPauseService $service): JsonResponse|RedirectResponse
    {
        $user = $this->authorizePortal($request);

        $validated = $request->validate([
            'scope_type' => ['required', 'string', Rule::in(SlaPause::SCOPES)],
            'scope_value' => ['nullable', 'string', 'max:191'],
            'logistics_company_id' => ['nullable', 'integer', 'exists:logistics_companies,id'],
            'logistics_hub_id' => ['nullable', 'integer', 'exists:logistics_hubs,id'],
            'reason_code' => ['required', 'string', Rule::in(SlaPause::REASONS)],
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'ends_at' => ['nullable', 'date'],
        ]);

        if (in_array($validated['scope_type'], ['platform', 'province'], true) && ! $user->isAdmin()) {
            abort(403, 'Only Platform Admins may create platform or province Force-Majeure pauses.');
        }

        try {
            $pause = $service->activate($user, $validated);
        } catch (DomainException $error) {
            return $this->domainError($request, $error);
        }

        return ($request->expectsJson() && ! $request->header('X-Inertia'))
            ? response()->json(['pause' => $pause], 201)->header('Cache-Control', 'private, no-store')
            : back()->with('success', "Force-Majeure SLA pause {$pause->reference} activated.");
    }

    public function resumePause(Request $request, SlaPause $pause, SlaPauseService $service): JsonResponse|RedirectResponse
    {
        $user = $this->authorizePortal($request);

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! $user->isAdmin() && ! $this->eligibility->isCompanyManager($user, $pause->logistics_company_id)) {
            abort(403, 'You are not authorized to resume this Force-Majeure SLA pause.');
        }

        try {
            $resumed = $service->resume($pause, $user, $validated['notes'] ?? null);
        } catch (DomainException $error) {
            return $this->domainError($request, $error);
        }

        return ($request->expectsJson() && ! $request->header('X-Inertia'))
            ? response()->json(['pause' => $resumed])->header('Cache-Control', 'private, no-store')
            : back()->with('success', "Force-Majeure SLA pause {$resumed->reference} resumed and active deadlines extended.");
    }

    public function dryRun(Request $request, StateMonitoringService $service): JsonResponse
    {
        $user = $this->authorizePortal($request);
        abort_unless($user->isAdmin(), 403, 'Only Platform Admins may run monitoring dry-run previews.');

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $summary = $service->scan(dryRun: true, limit: (int) ($validated['limit'] ?? 100));

        return response()->json($summary)->header('Cache-Control', 'private, no-store');
    }

    public function storeInvestigation(Request $request, MissingParcelInvestigationService $service): JsonResponse|RedirectResponse
    {
        $user = $this->authorizePortal($request);

        $validated = $request->validate([
            'delivery_id' => ['required', 'integer', 'exists:deliveries,id'],
            'summary' => ['required', 'string', 'min:10', 'max:1000'],
            'hub_id' => ['nullable', 'integer', 'exists:logistics_hubs,id'],
            'investigator_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $delivery = Delivery::findOrFail((int) $validated['delivery_id']);
        $this->authorizeCompanyScope($user, $delivery->logistics_company_id);

        try {
            $investigation = $service->openManual($user, $delivery, $validated);
        } catch (DomainException $error) {
            return $this->domainError($request, $error);
        }

        return ($request->expectsJson() && ! $request->header('X-Inertia'))
            ? response()->json(['investigation' => $investigation], 201)->header('Cache-Control', 'private, no-store')
            : back()->with('success', "Missing parcel investigation {$investigation->reference} opened.");
    }

    public function assignInvestigation(Request $request, MissingParcelInvestigation $investigation, MissingParcelInvestigationService $service): JsonResponse|RedirectResponse
    {
        $user = $this->authorizePortal($request);
        $this->authorizeCompanyScope($user, $investigation->logistics_company_id);

        $validated = $request->validate([
            'investigator_id' => ['required', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $updated = $service->assignInvestigator($investigation, $user, (int) $validated['investigator_id'], (string) ($validated['notes'] ?? ''));
        } catch (DomainException $error) {
            return $this->domainError($request, $error);
        }

        return ($request->expectsJson() && ! $request->header('X-Inertia'))
            ? response()->json(['investigation' => $updated])->header('Cache-Control', 'private, no-store')
            : back()->with('success', "Investigator assigned to {$updated->reference}.");
    }

    public function addFinding(Request $request, MissingParcelInvestigation $investigation, MissingParcelInvestigationService $service): JsonResponse|RedirectResponse
    {
        $user = $this->authorizePortal($request);
        $this->authorizeCompanyScope($user, $investigation->logistics_company_id);

        $validated = $request->validate([
            'notes' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        try {
            $updated = $service->addFinding($investigation, $user, $validated['notes']);
        } catch (DomainException $error) {
            return $this->domainError($request, $error);
        }

        return ($request->expectsJson() && ! $request->header('X-Inertia'))
            ? response()->json(['investigation' => $updated])->header('Cache-Control', 'private, no-store')
            : back()->with('success', "Investigation finding recorded on {$updated->reference}.");
    }

    public function recoverInvestigation(Request $request, MissingParcelInvestigation $investigation, MissingParcelInvestigationService $service): JsonResponse|RedirectResponse
    {
        $user = $this->authorizePortal($request);
        $this->authorizeCompanyScope($user, $investigation->logistics_company_id);

        $validated = $request->validate([
            'notes' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        try {
            $updated = $service->resolveRecovered($investigation, $user, $validated['notes']);
        } catch (DomainException $error) {
            return $this->domainError($request, $error);
        }

        return ($request->expectsJson() && ! $request->header('X-Inertia'))
            ? response()->json(['investigation' => $updated])->header('Cache-Control', 'private, no-store')
            : back()->with('success', "Investigation {$updated->reference} marked recovered.");
    }

    public function confirmLossInvestigation(Request $request, MissingParcelInvestigation $investigation, MissingParcelInvestigationService $service): JsonResponse|RedirectResponse
    {
        $user = $this->authorizePortal($request);
        abort_unless($user->isAdmin(), 403, 'Only an authorized Platform Admin may confirm permanent parcel loss.');

        $validated = $request->validate([
            'notes' => ['required', 'string', 'min:5', 'max:1000'],
            'shipment_financial_plan_id' => ['nullable', 'integer', 'exists:shipment_financial_plans,id'],
        ]);

        try {
            $updated = $service->confirmLoss(
                $investigation,
                $user,
                $validated['notes'],
                isset($validated['shipment_financial_plan_id']) ? (int) $validated['shipment_financial_plan_id'] : null
            );
        } catch (DomainException $error) {
            return $this->domainError($request, $error);
        }

        return ($request->expectsJson() && ! $request->header('X-Inertia'))
            ? response()->json(['investigation' => $updated])->header('Cache-Control', 'private, no-store')
            : back()->with('success', "Permanent loss confirmed on {$updated->reference}.");
    }

    public function closeInvestigation(Request $request, MissingParcelInvestigation $investigation, MissingParcelInvestigationService $service): JsonResponse|RedirectResponse
    {
        $user = $this->authorizePortal($request);
        $this->authorizeCompanyScope($user, $investigation->logistics_company_id);

        $validated = $request->validate([
            'notes' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        try {
            $updated = $service->closeUnfounded($investigation, $user, $validated['notes']);
        } catch (DomainException $error) {
            return $this->domainError($request, $error);
        }

        return ($request->expectsJson() && ! $request->header('X-Inertia'))
            ? response()->json(['investigation' => $updated])->header('Cache-Control', 'private, no-store')
            : back()->with('success', "Investigation {$updated->reference} closed as unfounded.");
    }

    public function storePickupPause(Request $request, DispatchRecoveryService $service): JsonResponse|RedirectResponse
    {
        $user = $this->authorizePortal($request);

        $validated = $request->validate([
            'logistics_hub_id' => ['required', 'integer', 'exists:logistics_hubs,id'],
            'barangay' => ['nullable', 'string', 'max:120'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $hub = LogisticsHub::findOrFail((int) $validated['logistics_hub_id']);
        $this->authorizeHubScope($user, $hub);

        try {
            $pause = $service->pausePickup($user, $hub, $validated['barangay'] ?? null, $validated['reason']);
        } catch (DomainException $error) {
            return $this->domainError($request, $error);
        }

        return ($request->expectsJson() && ! $request->header('X-Inertia'))
            ? response()->json(['pickup_pause' => $pause], 201)->header('Cache-Control', 'private, no-store')
            : back()->with('success', "Hub pickup pause {$pause->reference} activated.");
    }

    public function resumePickupPause(Request $request, HubPickupPause $pause, DispatchRecoveryService $service): JsonResponse|RedirectResponse
    {
        $user = $this->authorizePortal($request);
        $pause->loadMissing('hub');
        abort_unless(
            $user->isAdmin()
                || $this->eligibility->isCompanyManager($user, $pause->logistics_company_id)
                || ($pause->hub && $this->eligibility->canScan($user, $pause->hub)),
            403,
            'You are not authorized to resume this hub pickup pause.'
        );

        try {
            $resumed = $service->resumePickup($pause, $user);
        } catch (DomainException $error) {
            return $this->domainError($request, $error);
        }

        return ($request->expectsJson() && ! $request->header('X-Inertia'))
            ? response()->json(['pickup_pause' => $resumed])->header('Cache-Control', 'private, no-store')
            : back()->with('success', "Hub pickup pause {$resumed->reference} resumed.");
    }

    public function reassignRider(Request $request, Delivery $delivery, DispatchRecoveryService $service): JsonResponse|RedirectResponse
    {
        $user = $this->authorizePortal($request);

        $validated = $request->validate([
            'new_rider_id' => ['nullable', 'integer', 'exists:users,id'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $isFinalMilePhase = in_array($delivery->status, ['sorted_to_barangay_bin', 'assigned_to_rider', 'out_for_delivery', 'arrived_at_destination_hub'], true);
        $targetHubId = $isFinalMilePhase ? $delivery->destination_bayan_hub_id : $delivery->origin_bayan_hub_id;
        $targetHub = $targetHubId ? LogisticsHub::find($targetHubId) : null;

        abort_unless(
            $user->isAdmin()
                || $this->eligibility->isCompanyManager($user, $delivery->logistics_company_id)
                || ($targetHub && $this->eligibility->canScan($user, $targetHub)),
            403,
            'Only authorized hub handlers, company managers, or Platform Admins may reassign parcel riders.'
        );

        try {
            $result = $service->reassignRider(
                $delivery,
                $user,
                isset($validated['new_rider_id']) ? (int) $validated['new_rider_id'] : null,
                $validated['reason']
            );
        } catch (DomainException $error) {
            return $this->domainError($request, $error);
        }

        $label = $result['phase'] === 'final_mile' ? 'Final-mile rider reassigned.' : 'First-mile pickup assignment updated.';

        return ($request->expectsJson() && ! $request->header('X-Inertia'))
            ? response()->json($result)->header('Cache-Control', 'private, no-store')
            : back()->with('success', $label);
    }

    /**
     * @return array{0: User, 1: bool, 2: bool, 3: Collection<int, LogisticsHub>, 4: ?int, 5: ?int}
     */
    private function resolveScope(Request $request): array
    {
        $user = $this->authorizePortal($request);
        $isAdmin = $user->isAdmin();
        $isCompanyManager = $user->isLogistics() && $this->eligibility->isCompanyManager($user);
        $accessibleHubs = $this->eligibility->accessibleHubs($user)->orderBy('tier')->orderBy('name')->get();

        $companyId = $isAdmin
            ? ($request->filled('company_id') ? $request->integer('company_id') : null)
            : ($isCompanyManager
                ? $this->eligibility->managedCompanies($user)->value('id')
                : $accessibleHubs->first()?->logistics_company_id);

        $requestedHubId = $request->filled('hub_id') ? $request->integer('hub_id') : null;
        $hubId = null;
        if ($requestedHubId !== null) {
            $hub = $accessibleHubs->firstWhere('id', $requestedHubId);
            abort_unless($hub !== null, 403, 'The requested hub is outside your authorized scope.');
            $hubId = $hub->id;
        } elseif (! $isAdmin && ! $isCompanyManager) {
            $hubId = $accessibleHubs->first()?->id;
        }

        return [$user, $isAdmin, $isCompanyManager, $accessibleHubs, $companyId, $hubId];
    }

    private function authorizePortal(Request $request): User
    {
        $user = $request->user();
        abort_unless(
            $user
                && $user->canAccessPortal()
                && $user->email_verified_at !== null
                && ($user->isAdmin() || ($user->isLogistics() && ($this->eligibility->isCompanyManager($user) || $this->eligibility->accessibleHubs($user)->exists()))),
            403,
            'Only verified Platform Admins or authorized Logistics operators may access SLA monitoring controls.'
        );

        return $user;
    }

    private function authorizeCompanyScope(User $user, ?int $companyId): void
    {
        if ($user->isAdmin()) {
            return;
        }

        abort_unless(
            $companyId !== null && $this->eligibility->isCompanyManager($user, $companyId),
            403,
            'This operation requires a Platform Admin or owning Logistics Company Manager.'
        );
    }

    private function authorizeHubScope(User $user, LogisticsHub $hub): void
    {
        if ($user->isAdmin() || $this->eligibility->isCompanyManager($user, $hub->logistics_company_id)) {
            return;
        }

        abort_unless(
            $this->eligibility->canScan($user, $hub),
            403,
            'You are not an authorized handler for this hub.'
        );
    }

    private function domainError(Request $request, DomainException $error): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $error->getMessage()], 422)->header('Cache-Control', 'private, no-store');
        }

        return back()->withErrors(['monitoring' => $error->getMessage()])->with('error', $error->getMessage());
    }
}
