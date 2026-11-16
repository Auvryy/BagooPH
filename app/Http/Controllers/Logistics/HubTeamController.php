<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\CourierProfile;
use App\Models\HubHandler;
use App\Services\Logistics\CompanySetupService;
use App\Services\Logistics\LogisticsEligibilityService;
use App\Services\Logistics\RiderCompanyRequestService;
use App\Services\Logistics\StaffInvitationService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A hub's own team: the full-access hub account adds its intake and pack/dispatch workers and its riders,
 * changes their job, and answers rider applications. Everything is limited to that one hub.
 */
class HubTeamController extends Controller
{
    public function __construct(private readonly LogisticsEligibilityService $eligibility) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $hub = $this->eligibility->teamHub($user);
        abort_unless($hub, 403, 'Only a hub’s full-access account manages its team.');

        $workers = HubHandler::eligible()->where('hub_id', $hub->id)->with('user:id,name,email,must_change_password')
            ->orderBy('id')->get()->filter(fn (HubHandler $handler) => $handler->user)->values()
            ->map(fn (HubHandler $handler) => [
                'id' => $handler->id,
                'name' => $handler->user->name,
                'email' => $handler->user->email,
                'job_role' => in_array($handler->job_role, HubHandler::JOB_ROLES, true) ? $handler->job_role : HubHandler::JOB_ROLE_ALL,
                'is_you' => $handler->user_id === $user->id,
                'invite_pending' => (bool) $handler->user->must_change_password,
            ]);
        $riders = CourierProfile::where('assigned_hub_id', $hub->id)->where('logistics_company_id', $hub->logistics_company_id)
            ->with('user:id,name,email,must_change_password')->orderBy('id')->get()->filter(fn ($profile) => $profile->user)->values()
            ->map(fn (CourierProfile $profile) => [
                'id' => $profile->id,
                'name' => $profile->user->name,
                'email' => $profile->user->email,
                'on_duty' => (bool) $profile->is_available,
                'barangay' => $profile->assigned_barangay,
                'invite_pending' => (bool) $profile->user->must_change_password,
            ]);

        return Inertia::render('Hub/Team', [
            'hub' => ['id' => $hub->id, 'name' => $hub->name, 'code' => $hub->code, 'is_bayan' => $hub->isBayanHub(), 'company_id' => $hub->logistics_company_id],
            'workers' => $workers,
            'riders' => $riders,
            'riderIntake' => app(RiderCompanyRequestService::class)->companyWorkspace($user, $request->query()),
        ]);
    }

    public function store(Request $request, CompanySetupService $setup): RedirectResponse
    {
        $hub = $this->eligibility->teamHub($request->user());
        abort_unless($hub, 403, 'Only a hub’s full-access account manages its team.');
        try {
            $staff = $setup->staff($request->user(), $hub->logistics_company_id, [...$request->except('_token'), 'hub_id' => $hub->id]);
            $delivery = app(StaffInvitationService::class)->send($staff);
        } catch (DomainException $error) {
            return back()->withErrors(['team' => $error->getMessage()]);
        }
        $who = $staff->role === 'courier' ? 'Rider' : 'Worker';

        return back()->with($delivery['success'] ? 'success' : 'error', $delivery['success']
            ? "{$who} added. They got an email to set their password."
            : "{$who} added, but the email could not be sent. Try again from Company setup later.");
    }
}
