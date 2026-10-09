<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Rules\ApplicationText;
use App\Services\Courier\RiderApiInput;
use App\Services\Courier\RiderCashService;
use App\Services\Courier\RiderCommandService;
use App\Services\Finance\CodCashService;
use App\Services\Finance\CodMoney;
use Illuminate\Http\Request;

class RiderCashController extends Controller
{
    public function __construct(private readonly RiderCashService $views, private readonly CodCashService $cash, private readonly RiderCommandService $commands) {}

    public function index(Request $request)
    {
        return response()->json(['data' => $this->views->list($request)]);
    }

    public function show(Request $request, string $account)
    {
        $id = RiderApiInput::id($account);

        return response()->json(['data' => $this->views->resource($this->cash->account($request->user(), $id), $request->user())]);
    }

    public function offer(Request $request, string $account)
    {
        $id = RiderApiInput::id($account);
        $input = RiderApiInput::body($request, ['expected_version' => ['required', 'string', 'regex:/\A[1-9][0-9]*\z/'],
            'recipient_id' => ['required', 'string'], 'amount' => ['required', 'string', 'regex:'.CodMoney::RULE],
            'evidence_reference' => ['required', 'string', new ApplicationText('bin', 3, 120)]]);
        RiderApiInput::id($input['recipient_id']);
        RiderApiInput::id($input['expected_version']);
        $result = $this->commands->execute($request, 'cash.offer', 'cash-'.$id, $input, function () use ($request, $id, $input) {
            $account = $this->cash->account($request->user(), $id);
            $event = $this->cash->command($request->user(), $account, 'offer', $input + ['holder_id' => $request->user()->id,
                'request_token' => $request->header('Idempotency-Key')]);

            return ['cash_account_id' => (string) $account->id, 'event_reference' => $event->reference,
                'ledger_version' => (string) $event->sequence, 'handover_state' => 'awaiting_recipient_receipt'];
        });

        return response()->json(['data' => $result]);
    }
}
