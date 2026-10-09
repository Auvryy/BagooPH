<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Services\Courier\CourierOperationsService;
use App\Services\Courier\RiderApiInput;
use App\Services\Courier\RiderCommandService;
use App\Services\Courier\RiderTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RiderOperationsController extends Controller
{
    public function __construct(private readonly RiderTaskService $tasks, private readonly RiderCommandService $commands, private readonly CourierOperationsService $operations) {}

    public function home(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->tasks->home($request->user())]);
    }

    public function available(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->tasks->list($request, 'available')]);
    }

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate(['phase' => ['required', Rule::in(['pickup', 'final_mile'])]]);

        return response()->json(['data' => $this->tasks->list($request, $input['phase'])]);
    }

    public function show(Request $request, string $task): JsonResponse
    {
        [$parcel, $phase] = $this->tasks->task($request->user(), $task);

        return response()->json(['data' => $this->tasks->resource($parcel, $phase, $request->user())]);
    }

    public function duty(Request $request): JsonResponse
    {
        $input = RiderApiInput::body($request, ['on_duty' => ['required', 'boolean']]);
        if (! is_bool($input['on_duty'])) {
            RiderApiInput::error('VALIDATION_FAILED', 'Supply a JSON boolean for on_duty.', 422, ['on_duty' => ['A boolean is required.']]);
        }
        $result = $this->commands->execute($request, 'duty', 'rider', $input,
            fn () => ['on_duty' => $this->operations->setAvailability($request->user(), $input['on_duty'])->is_available]);

        return response()->json(['data' => $result]);
    }

    public function claim(Request $request, string $job): JsonResponse
    {
        $id = RiderApiInput::id($job);
        $input = RiderApiInput::body($request, ['expected_version' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/']]);
        $result = $this->commands->execute($request, 'claim', 'pickup-'.$id, $input, function () use ($request, $id, $input) {
            $profile = $request->user()->courierProfile;
            abort_unless($profile?->logistics_company_id && $profile->assigned_hub_id, 403);
            $parcel = Delivery::where('logistics_company_id', $profile->logistics_company_id)
                ->where('origin_bayan_hub_id', $profile->assigned_hub_id)->whereKey($id)->firstOrFail();
            $parcel = $this->tasks->lock($parcel);
            $this->tasks->assertVersion($parcel, $input['expected_version']);

            return $this->tasks->result($this->operations->claimPickup($request->user(), $parcel), 'pickup');
        });

        return response()->json(['data' => $result]);
    }

    public function command(Request $request, string $key): JsonResponse
    {
        return response()->json(['data' => $this->commands->owned($request, $key)]);
    }
}
