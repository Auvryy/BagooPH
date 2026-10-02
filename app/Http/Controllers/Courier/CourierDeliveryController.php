<?php

namespace App\Http\Controllers\Courier;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Services\Courier\CourierMessagingService;
use App\Services\Courier\CourierOperationsService;
use App\Services\Logistics\OrderStateMachineService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class CourierDeliveryController extends Controller
{
    public function __construct(
        private readonly CourierOperationsService $operations,
        private readonly CourierMessagingService $messaging,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user()->load([
            'courierProfile.company',
            'courierProfile.hub',
            'courierProfile.vehicle',
        ]);
        $profile = $user->courierProfile;

        $availableJobs = collect();
        $pickupTasks = collect();
        $finalMileTasks = collect();
        $recentActivity = collect();
        $completedToday = 0;
        $canReceiveNewWork = (bool) (
            $profile?->logistics_company_id
            && $profile?->assigned_hub_id
            && $profile?->company?->is_active
            && $profile?->company?->status === 'active'
            && $profile?->hub?->is_active
        );

        if ($profile?->logistics_company_id && $profile->assigned_hub_id) {
            if ($canReceiveNewWork) {
                $availableJobs = Delivery::query()
                    ->whereNull('courier_id')
                    ->whereRaw('deliveries.status = ?', ['unassigned'])
                    ->where('logistics_company_id', $profile->logistics_company_id)
                    ->where('origin_bayan_hub_id', $profile->assigned_hub_id)
                    ->whereHas('order', fn ($query) => $query->where('status', OrderStateMachineService::STATUS_READY_FOR_PICKUP))
                    ->with(['order.items', 'originBayanHub'])
                    ->oldest()
                    ->get();
            }

            $pickupTasks = Delivery::query()
                ->where('courier_id', $user->id)
                ->where('logistics_company_id', $profile->logistics_company_id)
                ->where('origin_bayan_hub_id', $profile->assigned_hub_id)
                ->whereRaw("deliveries.status in ('assigned', 'assigned_pickup', 'picked_up')")
                ->with(['order.items', 'originBayanHub'])
                ->oldest('assigned_at')
                ->get();

            $finalMileTasks = Delivery::query()
                ->where('assigned_rider_id', $user->id)
                ->where('logistics_company_id', $profile->logistics_company_id)
                ->where('destination_bayan_hub_id', $profile->assigned_hub_id)
                ->whereRaw("deliveries.status in ('assigned_to_rider', 'out_for_delivery')")
                ->with(['order', 'destinationBayanHub'])
                ->oldest('assigned_at')
                ->get();

            $recentActivity = Delivery::query()
                ->where('assigned_rider_id', $user->id)
                ->where('logistics_company_id', $profile->logistics_company_id)
                ->where('destination_bayan_hub_id', $profile->assigned_hub_id)
                ->whereRaw('deliveries.status = ?', [OrderStateMachineService::STATUS_DELIVERED])
                ->with(['order', 'destinationBayanHub'])
                ->latest('delivered_at')
                ->limit(10)
                ->get();

            $completedToday = Delivery::query()
                ->where('assigned_rider_id', $user->id)
                ->where('logistics_company_id', $profile->logistics_company_id)
                ->where('destination_bayan_hub_id', $profile->assigned_hub_id)
                ->whereRaw('deliveries.status = ?', [OrderStateMachineService::STATUS_DELIVERED])
                ->whereDate('delivered_at', today())
                ->count();
        }

        return Inertia::render('Courier/Deliveries', [
            'scope' => $this->scopePayload($profile),
            'isOnline' => (bool) $profile?->is_available,
            'stats' => [
                'availablePickups' => $availableJobs->count(),
                'activePickups' => $pickupTasks->count(),
                'activePickupLimit' => Delivery::MAX_ACTIVE_PICKUPS_PER_RIDER,
                'finalMileTasks' => $finalMileTasks->count(),
                'completedToday' => $completedToday,
            ],
            'queues' => [
                'availablePickups' => $availableJobs->map(fn (Delivery $delivery) => $this->pickupPayload($delivery, true))->values(),
                'pickupTasks' => $pickupTasks->map(fn (Delivery $delivery) => $this->pickupPayload($delivery, false))->values(),
                'finalMileTasks' => $finalMileTasks->map(fn (Delivery $delivery) => $this->finalMilePayload($delivery))->values(),
                'recentActivity' => $recentActivity->map(fn (Delivery $delivery) => $this->activityPayload($delivery))->values(),
            ],
        ]);
    }

    public function claim(Request $request, Delivery $delivery): RedirectResponse
    {
        try {
            $claimed = $this->operations->claimPickup(
                $request->user()->load('courierProfile'),
                $delivery
            );
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', "Pickup {$claimed->tracking_number} claimed. Proceed to the merchant store.");
    }

    public function updateStatus(Request $request, Delivery $delivery): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in([
                OrderStateMachineService::STATUS_PICKED_UP,
                OrderStateMachineService::STATUS_OUT_FOR_DELIVERY,
                OrderStateMachineService::STATUS_DELIVERED,
            ])],
            'courier_notes' => ['nullable', 'string', 'max:500'],
            'proof_image_file' => [
                Rule::requiredIf(
                    $request->input('status') === OrderStateMachineService::STATUS_DELIVERED
                    && $delivery->status !== OrderStateMachineService::STATUS_DELIVERED
                ),
                'nullable',
                'image',
                'max:5120',
            ],
        ]);

        $rider = $request->user()->load('courierProfile');
        $targetStatus = $validated['status'];
        $proofPath = null;

        $isIdempotentRetry = $delivery->status === $targetStatus
            && DeliveryCheckpoint::query()
                ->where('delivery_id', $delivery->id)
                ->where('checkpoint_type', $targetStatus)
                ->exists();

        if (! $isIdempotentRetry && $request->hasFile('proof_image_file')) {
            $storedPath = $request->file('proof_image_file')->store('delivery-proofs', 'public');
            $proofPath = '/storage/'.$storedPath;
        }

        try {
            $updatedDelivery = app(OrderStateMachineService::class)->transition(
                delivery: $delivery,
                targetStatus: $targetStatus,
                actor: $rider,
                scanMetadata: [
                    'rider_id' => $rider->id,
                    'location_name' => $targetStatus === OrderStateMachineService::STATUS_PICKED_UP
                        ? ($delivery->pickup_store_name ?? 'Merchant store')
                        : ($delivery->delivery_address ?? 'Buyer destination'),
                    'notes' => $validated['courier_notes'] ?? null,
                    'proof_image' => $proofPath,
                ]
            );
        } catch (DomainException $exception) {
            if ($proofPath) {
                Storage::disk('public')->delete(str_replace('/storage/', '', $proofPath));
            }

            return back()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            if ($proofPath) {
                Storage::disk('public')->delete(str_replace('/storage/', '', $proofPath));
            }

            throw $exception;
        }

        $updatedDelivery->update([
            'courier_notes' => $validated['courier_notes'] ?? $updatedDelivery->courier_notes,
        ]);

        if ($targetStatus === OrderStateMachineService::STATUS_PICKED_UP) {
            DeliveryCheckpoint::firstOrCreate(
                ['delivery_id' => $delivery->id, 'checkpoint_type' => 'courier_pickup'],
                [
                    'location_name' => $delivery->pickup_store_name ?? 'Merchant store',
                    'barcode_scanned' => $delivery->tracking_number,
                    'notes' => $validated['courier_notes'] ?? 'Pickup rider matched the waybill and collected the seller parcel.',
                    'scanned_by_id' => $rider->id,
                ]
            );
        }

        $message = match ($targetStatus) {
            OrderStateMachineService::STATUS_PICKED_UP => 'Pickup recorded. Deliver the parcel to the assigned Origin Bayan Hub.',
            OrderStateMachineService::STATUS_OUT_FOR_DELIVERY => 'Final-mile delivery started.',
            OrderStateMachineService::STATUS_DELIVERED => 'Delivery and proof recorded. Buyer confirmation is still required.',
        };

        return back()->with('success', $message);
    }

    public function earnings(Request $request): Response
    {
        $user = $request->user()->load([
            'courierProfile.company',
            'courierProfile.hub',
        ]);
        $profile = $user->courierProfile;
        $completed = collect();

        if ($profile?->logistics_company_id && $profile->assigned_hub_id) {
            $completed = Delivery::query()
                ->where('assigned_rider_id', $user->id)
                ->where('logistics_company_id', $profile->logistics_company_id)
                ->where('destination_bayan_hub_id', $profile->assigned_hub_id)
                ->whereRaw('deliveries.status = ?', [OrderStateMachineService::STATUS_DELIVERED])
                ->with(['order', 'destinationBayanHub'])
                ->latest('delivered_at')
                ->get();
        }

        return Inertia::render('Courier/Earnings', [
            'scope' => $this->scopePayload($profile),
            'isOnline' => (bool) $profile?->is_available,
            'summary' => [
                'completedDeliveries' => $completed->count(),
                'completedToday' => $completed->filter(fn (Delivery $delivery) => $delivery->delivered_at?->isToday())->count(),
            ],
            'trips' => $completed->map(fn (Delivery $delivery) => $this->activityPayload($delivery))->values(),
        ]);
    }

    public function messages(Request $request): Response
    {
        $user = $request->user()->load('courierProfile');
        $selectedDeliveryId = $request->integer('delivery') ?: null;

        return Inertia::render('Courier/Messages', [
            'conversations' => $this->messaging->conversations($user),
            'currentUserId' => $user->id,
            'selectedDeliveryId' => $selectedDeliveryId,
            'isOnline' => (bool) $user->courierProfile?->is_available,
        ]);
    }

    public function sendMessage(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'delivery_id' => ['required', 'integer', 'exists:deliveries,id'],
            'message' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $this->messaging->send(
                $request->user()->load('courierProfile'),
                Delivery::findOrFail($validated['delivery_id']),
                $validated['message']
            );
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Message sent.');
    }

    public function profile(Request $request): Response
    {
        $user = $request->user()->load([
            'courierProfile.company',
            'courierProfile.hub',
            'courierProfile.vehicle',
        ]);
        $profile = $user->courierProfile;
        $completedCount = 0;

        if ($profile?->logistics_company_id && $profile->assigned_hub_id) {
            $completedCount = Delivery::query()
                ->where('assigned_rider_id', $user->id)
                ->where('logistics_company_id', $profile->logistics_company_id)
                ->where('destination_bayan_hub_id', $profile->assigned_hub_id)
                ->whereRaw('deliveries.status = ?', [OrderStateMachineService::STATUS_DELIVERED])
                ->count();
        }

        return Inertia::render('Courier/Profile', [
            'rider' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'account_status' => $user->status,
                'kyc_status' => $user->kyc_status,
            ],
            'assignment' => [
                'company' => $profile?->company?->name,
                'hub' => $profile?->hub?->name,
                'hub_code' => $profile?->hub?->code,
                'barangay' => $profile?->assigned_barangay,
            ],
            'vehicle' => [
                'type' => $profile?->vehicle?->vehicle_type ?? $profile?->vehicle_type,
                'model' => $profile?->vehicle?->model,
                'plate_number' => $profile?->vehicle?->plate_number ?? $profile?->plate_number,
                'fleet_status' => $profile?->vehicle?->status,
                'license_number' => $profile?->license_number,
                'registration_status' => $profile?->or_cr_status,
            ],
            'isOnline' => (bool) $profile?->is_available,
            'completedDeliveries' => $completedCount,
        ]);
    }

    public function toggleDuty(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'is_available' => ['required', 'boolean'],
        ]);

        try {
            $profile = $this->operations->setAvailability(
                $request->user()->load('courierProfile'),
                (bool) $validated['is_available']
            );
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with(
            'success',
            $profile->is_available
                ? 'You are on duty and may receive eligible work.'
                : 'You are off duty. Existing assignments remain your responsibility.'
        );
    }

    private function scopePayload($profile): array
    {
        return [
            'company' => $profile?->company?->name,
            'hub' => $profile?->hub?->name,
            'hubCode' => $profile?->hub?->code,
            'barangay' => $profile?->assigned_barangay,
            'isAssigned' => (bool) ($profile?->logistics_company_id && $profile?->assigned_hub_id),
            'isOperational' => (bool) (
                $profile?->company?->is_active
                && $profile?->company?->status === 'active'
                && $profile?->hub?->is_active
            ),
        ];
    }

    private function pickupPayload(Delivery $delivery, bool $isAvailable): array
    {
        return [
            'id' => $delivery->id,
            'trackingNumber' => $delivery->tracking_number,
            'orderNumber' => $delivery->order?->order_number,
            'status' => $delivery->status,
            'itemCount' => $delivery->order?->items?->sum('quantity') ?? 0,
            'merchant' => [
                'name' => $delivery->pickup_store_name,
                'address' => $delivery->pickup_address,
                'phone' => $delivery->pickup_phone,
            ],
            'originHub' => [
                'name' => $delivery->originBayanHub?->name,
                'code' => $delivery->originBayanHub?->code,
            ],
            'assignedAt' => $delivery->assigned_at?->toIso8601String(),
            'nextAction' => $isAvailable
                ? 'claim_pickup'
                : match ($delivery->status) {
                    'assigned', 'assigned_pickup' => 'confirm_pickup',
                    'picked_up' => 'await_origin_hub_scan',
                    default => null,
                },
            'canMessage' => ! $isAvailable && in_array($delivery->status, ['assigned', 'assigned_pickup', 'picked_up'], true),
        ];
    }

    private function finalMilePayload(Delivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'trackingNumber' => $delivery->tracking_number,
            'orderNumber' => $delivery->order?->order_number,
            'status' => $delivery->status,
            'recipient' => [
                'name' => $delivery->delivery_recipient_name,
                'address' => $delivery->delivery_address,
                'phone' => $delivery->delivery_phone,
            ],
            'payment' => [
                'method' => strtoupper((string) $delivery->order?->payment_method),
                'codAmount' => $delivery->order?->payment_method === 'cod'
                    ? (float) $delivery->order->total_amount
                    : null,
            ],
            'destinationHub' => [
                'name' => $delivery->destinationBayanHub?->name,
                'code' => $delivery->destinationBayanHub?->code,
            ],
            'assignedAt' => $delivery->assigned_at?->toIso8601String(),
            'nextAction' => $delivery->status === OrderStateMachineService::STATUS_ASSIGNED_TO_RIDER
                ? 'start_delivery'
                : 'complete_delivery',
            'canMessage' => true,
        ];
    }

    private function activityPayload(Delivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'trackingNumber' => $delivery->tracking_number,
            'orderNumber' => $delivery->order?->order_number,
            'recipientName' => $delivery->delivery_recipient_name,
            'deliveryAddress' => $delivery->delivery_address,
            'paymentMethod' => strtoupper((string) $delivery->order?->payment_method),
            'destinationHub' => $delivery->destinationBayanHub?->name,
            'deliveredAt' => $delivery->delivered_at?->toIso8601String(),
        ];
    }
}
