<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Logistics\LogisticsHubWorkstationController;
use App\Services\Logistics\LogisticsAccountService;
use App\Services\Logistics\WaybillScanInputService;
use Illuminate\Http\Request;

class LogisticsScanController extends Controller
{
    public function scan(Request $request, LogisticsAccountService $accounts, LogisticsHubWorkstationController $workstation)
    {
        $request->merge(app(WaybillScanInputService::class)->normalize($request->only(['barcode', 'action', 'expected_status']), allowTrackingUrl: true));
        $data = $request->validate(['hub_id' => 'required|integer|min:1', 'mode' => 'required|in:inspect,confirm',
            'action' => 'nullable|string|not_in:AUTO_PROGRESS', 'expected_status' => 'nullable|string', 'continuous' => 'prohibited', 'auto_advance' => 'prohibited']);
        abort_unless($accounts->hubs($request->user())->contains('id', $data['hub_id']), 403, 'This facility is not assigned to your operator account.');

        $barcode = $request->input('barcode');
        $isBag = is_string($barcode) && (str_starts_with($barcode, 'BAG-') || str_starts_with($barcode, 'RMB-'));
        abort_if($isBag && $data['mode'] === 'confirm', 409, 'Verify the bag seal and receive its parcels through hub bag receiving.');
        $response = $workstation->scanIntake($request);
        if ($isBag && $response->isSuccessful()) {
            $payload = $response->getData(true);
            if (isset($payload['prompt'])) {
                $payload['prompt']['requires_confirmation'] = false;
                $payload['message'] = 'Bag identified. Continue in hub bag receiving to verify its seal and receive parcels.';
                $response->setData($payload);
            }
        }

        return $response;
    }
}
