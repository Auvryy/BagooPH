<?php

namespace App\Http\Controllers\Courier;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsHub;
use App\Rules\ApplicationText;
use App\Services\AccountSettingsService;
use App\Services\Courier\CourierMessagingService;
use App\Services\Courier\CourierOperationsService;
use App\Services\Finance\CodCashService;
use App\Services\Finance\CodMoney;
use App\Services\IdentityCorrectionService;
use App\Services\Logistics\DeliveryRecoveryService;
use App\Services\Logistics\LogisticsEligibilityService;
use App\Services\Logistics\OrderStateMachineService;
use App\Services\Logistics\WaybillScanInputService;
use App\Services\ProfileInputService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        $todayStart = today('Asia/Manila')->utc();
        $todayEnd = $todayStart->copy()->addDay();

        if ($profile?->logistics_company_id && $profile->assigned_hub_id) {
            $availableJobs = $this->operations->queue($user, 'available')->get();
            $pickupTasks = $this->operations->queue($user, 'pickup')->get();
            $finalMileTasks = $this->operations->queue($user, 'final_mile')->get();

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
                ->where('delivered_at', '>=', $todayStart)
                ->where('delivered_at', '<', $todayEnd)
                ->count();
        }

        return Inertia::render('Courier/Deliveries', [
            'recoveryRequestToken' => (string) Str::uuid(),
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

    private function reportFailure(Request $request, Delivery $delivery): RedirectResponse
    {
        $inputs = app(WaybillScanInputService::class);
        $request->merge($inputs->normalize(['barcode' => $request->input('barcode'), 'notes' => $request->input('courier_notes')]));
        $validated = $request->validate([
            'barcode' => $inputs->barcodeRules(), 'notes' => ['required', ...$inputs->notesRules(500)],
            'failure_reason' => ['required', Rule::in(array_keys(DeliveryRecoveryService::REASONS))],
            'location_name' => ['required', ...$inputs->notesRules(255)],
            'request_token' => ['required', 'uuid'], 'proof_image_file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $path = $request->file('proof_image_file')->storeAs('delivery-attempt-proofs', (string) Str::uuid().'.'.$request->file('proof_image_file')->extension(), 'local');
        abort_unless(is_string($path), 503, 'The attempt proof could not be saved. Please retry.');
        try {
            app(DeliveryRecoveryService::class)->fail($delivery, $request->user(), [
                'barcode' => $validated['barcode'], 'reason' => $validated['failure_reason'], 'notes' => $validated['notes'],
                'request_token' => $validated['request_token'], 'proof_path' => $path,
                'location_name' => $validated['location_name'],
            ]);
            if (! DeliveryAttempt::where('delivery_id', $delivery->id)->where('proof_path', $path)->exists()) {
                Storage::disk('local')->delete($path);
            }
        } catch (DomainException $exception) {
            Storage::disk('local')->delete($path);

            return back()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return back()->with('success', 'Attempt recorded. Return the parcel to the destination Bayan Hub for its inbound scan.');
    }

    public function updateStatus(Request $request, Delivery $delivery): RedirectResponse
    {
        if ($request->input('status') === OrderStateMachineService::STATUS_DELIVERY_FAILED) {
            return $this->reportFailure($request, $delivery);
        }
        $inputs = app(WaybillScanInputService::class);
        $scanInput = $inputs->normalize(['barcode' => $request->input('barcode'), 'notes' => $request->input('courier_notes')]);
        $request->merge(['barcode' => $scanInput['barcode'], 'courier_notes' => $scanInput['notes']]);
        $validated = $request->validate([
            'status' => ['required', Rule::in([
                OrderStateMachineService::STATUS_PICKED_UP,
                OrderStateMachineService::STATUS_OUT_FOR_DELIVERY,
                OrderStateMachineService::STATUS_DELIVERED,
            ])],
            'barcode' => $inputs->barcodeRules(),
            'courier_notes' => $inputs->notesRules(500),
            'recipient_name' => [Rule::requiredIf($request->input('status') === 'delivered'), 'nullable', 'string', new ApplicationText('name', 2, 255)],
            'recipient_relationship' => [Rule::requiredIf($request->input('status') === 'delivered'), 'nullable', Rule::in(['buyer', 'household', 'authorized_recipient'])],
            'cash_received' => [Rule::requiredIf($request->input('status') === 'delivered'), 'nullable', 'string', 'regex:'.CodMoney::RULE],
            'change_given' => [Rule::requiredIf($request->input('status') === 'delivered'), 'nullable', 'string', 'regex:'.CodMoney::RULE],
            'cash_confirmed' => ['accepted_if:status,delivered'],
            'request_token' => [Rule::requiredIf($request->input('status') === 'delivered'), 'nullable', 'uuid'],
            ...app(CodCashService::class)->protectedFields(),
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
        $courierNote = trim((string) ($validated['courier_notes'] ?? ''));
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
            $updatedDelivery = DB::transaction(function () use (
                $delivery,
                $targetStatus,
                $rider,
                $courierNote,
                $proofPath,
                $validated,
            ) {
                $updatedDelivery = app(OrderStateMachineService::class)->transition(
                    delivery: $delivery,
                    targetStatus: $targetStatus,
                    actor: $rider,
                    scanMetadata: [
                        'rider_id' => $rider->id,
                        'barcode' => $validated['barcode'] ?? null,
                        'location_name' => $targetStatus === OrderStateMachineService::STATUS_PICKED_UP
                            ? ($delivery->pickup_store_name ?? 'Merchant store')
                            : ($delivery->delivery_address ?? 'Buyer destination'),
                        'notes' => $courierNote !== '' ? $courierNote : null,
                        'proof_image' => $proofPath,
                        'proof_hash' => isset($validated['proof_image_file']) ? hash_file('sha256', $validated['proof_image_file']->getRealPath()) : null,
                        'recipient_name' => $validated['recipient_name'] ?? null,
                        'recipient_relationship' => $validated['recipient_relationship'] ?? null,
                        'cash_received' => $validated['cash_received'] ?? null,
                        'change_given' => $validated['change_given'] ?? null,
                        'cash_confirmed' => $validated['cash_confirmed'] ?? null,
                        'request_token' => $validated['request_token'] ?? null,
                    ]
                );

                if (! $updatedDelivery->wasChanged('status')) {
                    if ($proofPath) {
                        Storage::disk('public')->delete(str_replace('/storage/', '', $proofPath));
                    }

                    return $updatedDelivery;
                }

                $updatedDelivery->update([
                    'courier_notes' => $courierNote !== '' ? $courierNote : $updatedDelivery->courier_notes,
                ]);

                if (
                    $targetStatus === OrderStateMachineService::STATUS_PICKED_UP
                    && $courierNote !== ''
                ) {
                    $this->messaging->recordPickupNote($rider, $updatedDelivery, $courierNote);
                }

                if ($targetStatus === OrderStateMachineService::STATUS_PICKED_UP) {
                    $source = $updatedDelivery->checkpoints()->where('checkpoint_type', OrderStateMachineService::STATUS_PICKED_UP)->whereNull('source_checkpoint_id')->firstOrFail();
                    DeliveryCheckpoint::firstOrCreate(
                        ['delivery_id' => $updatedDelivery->id, 'checkpoint_type' => 'courier_pickup'],
                        [
                            'location_name' => $updatedDelivery->pickup_store_name ?? 'Merchant store',
                            'barcode_scanned' => $source->barcode_scanned,
                            'scan_provenance' => 'source_alias',
                            'source_checkpoint_id' => $source->id,
                            'source_state' => $source->source_state,
                            'target_state' => $source->target_state,
                            'custody_before' => $source->custody_before,
                            'custody_after' => $source->custody_after,
                            'notes' => $courierNote !== ''
                                ? $courierNote
                                : 'Pickup rider matched the waybill and collected the seller parcel.',
                            'scanned_by_id' => $rider->id,
                        ]
                    );
                }

                return $updatedDelivery;
            });
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

        $message = match ($targetStatus) {
            OrderStateMachineService::STATUS_PICKED_UP => 'Pickup recorded. Deliver the parcel to the assigned Origin Bayan Hub.',
            OrderStateMachineService::STATUS_OUT_FOR_DELIVERY => 'Final-mile delivery started.',
            OrderStateMachineService::STATUS_DELIVERED => 'Delivery, proof and exact COD recorded. Hand over the cash separately; the buyer still confirms receipt.',
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
                'completedToday' => $completed->filter(fn (Delivery $delivery) => $delivery->delivered_at
                    ?->copy()->timezone('Asia/Manila')->isSameDay(today('Asia/Manila')))->count(),
            ],
            'trips' => $completed->map(fn (Delivery $delivery) => $this->activityPayload($delivery))->values(),
        ]);
    }

    public function messages(Request $request): Response
    {
        $user = $request->user()->load(['courierProfile.company', 'courierProfile.hub']);
        $selectedDeliveryId = $request->integer('delivery') ?: null;

        return Inertia::render('Courier/Messages', [
            'conversations' => $this->messaging->conversations($user),
            'currentUserId' => $user->id,
            'selectedDeliveryId' => $selectedDeliveryId,
            'selectedPhase' => in_array($request->query('phase'), ['pickup', 'final_mile'], true)
                ? $request->query('phase') : null,
            'scope' => $this->scopePayload($user->courierProfile),
            'isOnline' => (bool) $user->courierProfile?->is_available,
        ]);
    }

    public function sendMessage(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'delivery_id' => ['required', 'integer', 'exists:deliveries,id'],
            'message' => ['required', 'string', 'max:1000'],
            'phase' => ['sometimes', 'required', Rule::in(['pickup', 'final_mile'])],
        ]);

        try {
            $this->messaging->send(
                $request->user()->load('courierProfile'),
                Delivery::findOrFail($validated['delivery_id']),
                $validated['message'],
                $validated['phase'] ?? null,
            );
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Message sent.');
    }

    public function acknowledgeMessages(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'delivery_id' => ['required', 'integer', 'exists:deliveries,id'],
            'phase' => ['required', Rule::in(['pickup', 'final_mile'])],
            'through_message_id' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $this->messaging->acknowledge(
                $request->user()->load('courierProfile'),
                Delivery::findOrFail($validated['delivery_id']),
                $validated['phase'],
                $validated['through_message_id'],
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        }

        return response()->json(['acknowledged' => true]);
    }

    public function profile(Request $request): Response
    {
        $user = $request->user()->load([
            'courierProfile.company',
            'courierProfile.hub',
            'courierProfile.vehicle',
        ]);
        $profile = $user->courierProfile;

        return Inertia::render('Courier/Profile', [
            ...app(AccountSettingsService::class)->presentation($user),
            'initialTab' => $request->is('account/settings') ? 'edit' : 'information',
            'rider' => [
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
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
            'scope' => $this->scopePayload($profile),
            'isOnline' => (bool) $profile?->is_available,
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        return app(IdentityCorrectionService::class)->mutateProfile($request, function () use ($request) {
            $validated = app(ProfileInputService::class)->validate($request, ['name', 'phone']);

            app(IdentityCorrectionService::class)->protectReviewedIdentity($request->user(), $validated);
            $request->user()->update([
                'name' => $validated['name'],
                'phone' => $validated['phone'] ?? null,
            ]);

            return back()->with('success', 'Account contact details updated.');
        });
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
        $hub = $profile?->hub;
        if ($hub?->logistics_company_id !== $profile?->logistics_company_id) {
            $hub = null;
        }

        return [
            'company' => $profile?->company?->name,
            'hub' => $hub?->name,
            'hubCode' => $hub?->code,
            'barangay' => $profile?->assigned_barangay,
            'isAssigned' => (bool) ($profile?->logistics_company_id && $profile?->assigned_hub_id),
            'isOperational' => app(LogisticsEligibilityService::class)->isOperational($profile),
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
            'originHub' => $this->hubPayload($delivery->originBayanHub),
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
                'latitude' => $delivery->order?->destination_latitude,
                'longitude' => $delivery->order?->destination_longitude,
            ],
            'payment' => [
                'method' => strtoupper((string) $delivery->order?->payment_method),
                'codAmount' => $delivery->order?->payment_method === 'cod'
                    ? (float) $delivery->order->total_amount
                    : null,
                'codAmountCents' => $delivery->order?->payment_method === 'cod'
                    ? CodMoney::cents($delivery->order->total_amount)
                    : null,
            ],
            'destinationHub' => $this->hubPayload($delivery->destinationBayanHub),
            'assignedAt' => $delivery->assigned_at?->toIso8601String(),
            'nextAction' => $delivery->status === OrderStateMachineService::STATUS_ASSIGNED_TO_RIDER
                ? 'start_delivery'
                : ($delivery->status === 'delivery_failed' ? 'return_to_hub' : 'complete_delivery'),
            'failureAttempts' => $delivery->failure_attempts,
            'failureReason' => DeliveryRecoveryService::REASONS[$delivery->failure_reason] ?? $delivery->failure_reason,
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

    private function hubPayload(?LogisticsHub $hub): array
    {
        return [
            'name' => $hub?->name,
            'code' => $hub?->code,
            'address' => $hub?->address,
            'latitude' => $hub?->latitude,
            'longitude' => $hub?->longitude,
        ];
    }
}
