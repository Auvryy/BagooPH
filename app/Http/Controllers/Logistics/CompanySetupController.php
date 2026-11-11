<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\User;
use App\Services\Logistics\CompanySetupService;
use App\Services\Logistics\StaffInvitationService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CompanySetupController extends Controller
{
    public function index(Request $request, CompanySetupService $service)
    {
        $actor = $request->user();
        $companies = LogisticsCompany::eligible()->when(! $actor->isAdmin(), fn ($q) => $q->where('user_id', $actor->id))->orderBy('name')->get();
        $company = $request->filled('company_id') ? $companies->firstWhere('id', (int) $request->query('company_id')) : $companies->first();
        abort_unless($company, 403, 'An approved company owner manages this setup.');
        $service->owner($actor, $company->id, allowPlatform: true);

        return Inertia::render('Hub/CompanySetup', [
            'companies' => $companies->map->only(['id', 'name']),
            'company' => $company->only(['id', 'name', 'base_shipping_cents', 'included_weight_grams', 'additional_kg_cents', 'shipping_earnings_model', 'tariff_version']),
            'canProvision' => $company->user_id === $actor->id,
            'hubs' => $company->hubs()->orderBy('tier')->orderBy('name')->get(['id', 'logistics_company_id', 'name', 'code', 'tier', 'province', 'city_municipality', 'parent_hub_id', 'postal_codes', 'coverage_barangays', 'is_active', 'latitude', 'longitude', 'address'])
                ->map(fn ($hub) => $hub->toArray() + ['coverage_token' => $service->coverageToken($hub), 'location_token' => $service->locationToken($hub)]),
            'staff' => DB::table('company_staff_provisions')->join('users', 'users.id', '=', 'company_staff_provisions.user_id')
                ->join('logistics_hubs', 'logistics_hubs.id', '=', 'company_staff_provisions.hub_id')
                ->where('company_staff_provisions.logistics_company_id', $company->id)
                ->get(['users.id', 'users.name', 'users.email', 'users.must_change_password', 'users.email_verified_at', 'kind', 'logistics_hubs.name as hub_name']),
            'basePath' => $request->is('hub/*') ? '/hub/company-setup' : '/company-setup',
        ]);
    }

    public function hub(Request $request, LogisticsCompany $company, CompanySetupService $service)
    {
        try {
            $service->hub($request->user(), $company->id, $request->all());
        } catch (DomainException $error) {
            return back()->withErrors(['setup' => $error->getMessage()]);
        }

        return back()->with('success', 'Hub created with its company, parent and service coverage.');
    }

    public function staff(Request $request, LogisticsCompany $company, CompanySetupService $service)
    {
        try {
            $staff = $service->staff($request->user(), $company->id, $request->all());
            $delivery = app(StaffInvitationService::class)->send($staff);
        } catch (DomainException $error) {
            return back()->withErrors(['setup' => $error->getMessage()]);
        }

        return back()->with($delivery['success'] ? 'success' : 'error', $delivery['success']
            ? 'Work account created. A setup code was emailed to the worker so they can accept their assignment and set their own password.'
            : 'Work account created, but the setup email could not be sent. Use Resend invitation to try again.');
    }

    public function tariff(Request $request, LogisticsCompany $company, CompanySetupService $service)
    {
        try {
            $service->tariff($request->user(), $company->id, $request->all());
        } catch (DomainException $error) {
            return back()->withErrors(['setup' => $error->getMessage()]);
        }

        return back()->with('success', 'Shipping settings saved for future quotes. Purchased orders retain their original terms.');
    }

    public function temporaryPassword(Request $request, LogisticsCompany $company, User $staff, CompanySetupService $service)
    {
        $service->resetTemporaryPassword($request->user(), $company->id, $staff, $request->all());

        $delivery = app(StaffInvitationService::class)->send($staff->fresh());

        return back()->with($delivery['success'] ? 'success' : 'error', $delivery['success'] ? 'A new setup code was emailed to the worker.' : 'The invitation was renewed, but its email could not be sent. Try again shortly.');
    }

    public function coverage(Request $request, LogisticsCompany $company, LogisticsHub $hub, CompanySetupService $service)
    {
        try {
            $service->coverage($request->user(), $company->id, $hub, $request->all());
        } catch (DomainException $error) {
            return back()->withErrors(['setup' => $error->getMessage()]);
        }

        return back()->with('success', 'Coverage saved for future parcel routes. Existing purchased routes retain their original path.');
    }

    public function location(Request $request, LogisticsCompany $company, LogisticsHub $hub, CompanySetupService $service)
    {
        try {
            $service->location($request->user(), $company->id, $hub, $request->all());
        } catch (DomainException $error) {
            return back()->withErrors(['location_token' => $error->getMessage()]);
        }

        return back()->with('success', 'Hub map pin saved.');
    }
}
