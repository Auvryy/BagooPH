<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryRecoveryEvent;
use App\Models\User;
use App\Services\Logistics\DeliveryRecoveryService;
use App\Services\Logistics\LogisticsEligibilityService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeliveryRecoveryController extends Controller
{
    public function index(Request $request): Response
    {
        $eligibility = app(LogisticsEligibilityService::class);
        $hubs = $eligibility->accessibleHubs($request->user())->pluck('id');
        $parcels = Delivery::whereIn('destination_bayan_hub_id', $hubs)
            ->whereHas('destinationBayanHub', fn ($hub) => $hub->whereColumn('logistics_hubs.logistics_company_id', 'deliveries.logistics_company_id'))
            ->whereRaw("deliveries.status in ('delivery_failed', 'return_to_sender')")
            ->with(['order', 'destinationBayanHub'])->latest('id')->paginate(20);
        $parcels->through(function (Delivery $delivery) use ($request, $eligibility) {
            $attempts = DeliveryAttempt::where('delivery_id', $delivery->id)->orderBy('attempt_number')->get();

            return ['id' => $delivery->id, 'trackingNumber' => $delivery->tracking_number, 'status' => $delivery->status,
                'hubName' => $delivery->destinationBayanHub?->name, 'atHub' => $delivery->current_hub_id === $delivery->destination_bayan_hub_id,
                'canReview' => $request->user()->isLogistics() && $eligibility->isCompanyAdministrator($request->user(), $delivery->logistics_company_id),
                'attempts' => $attempts->map(fn (DeliveryAttempt $attempt) => [
                    'id' => $attempt->id, 'reference' => $attempt->reference, 'number' => $attempt->attempt_number,
                    'riderName' => User::find($attempt->rider_id)?->name,
                    'reason' => DeliveryRecoveryService::REASONS[$attempt->reason_code] ?? $attempt->reason_code,
                    'notes' => $attempt->notes, 'attemptedAt' => $attempt->attempted_at->toIso8601String(),
                    'locationName' => $attempt->location_name,
                    'events' => DeliveryRecoveryEvent::where('delivery_attempt_id', $attempt->id)->orderBy('id')->get()->map(fn ($event) => [
                        'reference' => $event->reference, 'type' => $event->event_type, 'notes' => $event->notes,
                        'retryAt' => $event->retry_at?->toIso8601String(), 'recordedAt' => $event->created_at->toIso8601String(),
                    ]),
                ]),
            ];
        });

        return Inertia::render('Hub/DeliveryRecovery', ['deliveries' => $parcels, 'requestToken' => (string) Str::uuid(),
            'basePath' => $request->is('hub/*') ? '/hub' : '']);
    }

    public function approve(Request $request, Delivery $delivery): RedirectResponse
    {
        try {
            app(DeliveryRecoveryService::class)->approveRetry($delivery, $request->user(), $request->only(['notes', 'retry_at', 'request_token', 'attempt_reference']));
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Retry date approved. The destination handler must scan the parcel when that date arrives.');
    }

    public function proof(Request $request, DeliveryAttempt $attempt): StreamedResponse
    {
        $delivery = $attempt->delivery;
        $actor = $request->user();
        $eligibility = app(LogisticsEligibilityService::class);
        $allowed = $actor?->canAccessPortal() && ($actor->isAdmin()
            || ($actor->isCourier() && $actor->id === $attempt->rider_id)
            || ($actor->isLogistics() && $eligibility->accessibleHubs($actor)->whereKey($delivery->destination_bayan_hub_id)
                ->where('logistics_company_id', $delivery->logistics_company_id)->exists()));
        abort_unless($allowed, 403);
        abort_unless(Storage::disk('local')->exists($attempt->proof_path), 404);

        return Storage::disk('local')->response($attempt->proof_path, 'attempt-proof', ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
