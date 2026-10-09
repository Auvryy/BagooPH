<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Rules\ApplicationText;
use App\Services\Courier\CourierOutcomeService;
use App\Services\Courier\CourierProofService;
use App\Services\Courier\RiderApiInput;
use App\Services\Courier\RiderCommandService;
use App\Services\Courier\RiderTaskService;
use App\Services\Finance\CodMoney;
use App\Services\Logistics\DeliveryRecoveryService;
use App\Services\Logistics\WaybillScanInputService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RiderParcelController extends Controller
{
    public function __construct(private readonly RiderTaskService $tasks, private readonly RiderCommandService $commands, private readonly CourierProofService $proofs) {}

    public function pickup(Request $request, string $task): JsonResponse
    {
        return $this->record($request, $task, 'pickup');
    }

    public function depart(Request $request, string $task): JsonResponse
    {
        return $this->record($request, $task, 'depart');
    }

    public function deliver(Request $request, string $task): JsonResponse
    {
        return $this->record($request, $task, 'deliver');
    }

    public function fail(Request $request, string $task): JsonResponse
    {
        return $this->record($request, $task, 'fail');
    }

    private function record(Request $request, string $task, string $action): JsonResponse
    {
        $scan = app(WaybillScanInputService::class);
        $request->merge($scan->normalize($request->except('proof_image_file')));
        $rules = ['expected_version' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'barcode' => $scan->barcodeRules(), 'notes' => $scan->notesRules(500)];
        if (in_array($action, ['deliver', 'fail'], true)) {
            $rules['proof_image_file'] = ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'];
        }
        if ($action === 'deliver') {
            $rules += ['recipient_name' => ['required', 'string', new ApplicationText('name', 2, 255)],
                'recipient_relationship' => ['required', Rule::in(['buyer', 'household', 'authorized_recipient'])],
                'cash_received' => ['required', 'string', 'regex:'.CodMoney::RULE],
                'change_given' => ['required', 'string', 'regex:'.CodMoney::RULE], 'cash_confirmed' => ['required', 'accepted']];
        }
        if ($action === 'fail') {
            $rules['notes'] = ['required', ...$scan->notesRules(500)];
            $rules += ['reason' => ['required', Rule::in(array_keys(DeliveryRecoveryService::REASONS))],
                'location_name' => ['required', ...$scan->notesRules(255)]];
        }
        $input = RiderApiInput::body($request, $rules);
        $intent = $input;
        if (isset($intent['proof_image_file'])) {
            $intent['proof_image_file'] = hash_file('sha256', $input['proof_image_file']->getRealPath());
        }
        $path = null;
        try {
            $result = $this->commands->execute($request, $action, $task, $intent, function () use ($request, $task, $action, $input, &$path) {
                [$parcel, $phase] = $this->tasks->task($request->user(), $task);
                abort_unless(($action === 'pickup' && $phase === 'pickup') || ($action !== 'pickup' && $phase === 'final_mile'), 403);
                $parcel = $this->tasks->lock($parcel);
                // Recheck the current scope after the parent locks; hints and the first read are not authority.
                $this->tasks->task($request->user()->fresh(), $task);
                $this->tasks->assertVersion($parcel, $input['expected_version']);
                $evidence = array_diff_key($input, array_flip(['expected_version', 'proof_image_file']));
                $evidence['request_token'] = $request->header('Idempotency-Key');
                if (isset($input['proof_image_file'])) {
                    $path = $this->proofs->store($input['proof_image_file'], $action === 'fail');
                    $evidence[$action === 'fail' ? 'proof_path' : 'proof_image'] = $path;
                    $evidence['proof_hash'] = $this->proofs->hash($path);
                }
                if ($action === 'fail') {
                    $updated = app(DeliveryRecoveryService::class)->fail($parcel, $request->user(), $evidence);
                } else {
                    $evidence['location_name'] = $action === 'pickup' ? ($parcel->pickup_store_name ?? 'Merchant store')
                        : $parcel->order->shipping_address;
                    $target = match ($action) {
                        'pickup' => 'picked_up', 'depart' => 'out_for_delivery', 'deliver' => 'delivered'
                    };
                    $updated = app(CourierOutcomeService::class)->record($request->user(), $parcel, $target, $evidence);
                }

                return $this->tasks->result($updated->fresh(['order']), $phase);
            });

            return response()->json(['data' => $result]);
        } finally {
            $this->proofs->discardUnused($path);
        }
    }
}
