<?php

namespace App\Services\Wallet;

use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletTopUpRequest;
use App\Rules\ApplicationText;
use App\Services\BuyerAccessService;
use App\Services\Finance\CodMoney;
use App\Services\Notifications\NotificationDeliveryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WalletCreditService
{
    public const MAX_TOP_UP_CENTS = 10000000;

    public const MAX_GRANTED_BALANCE_CENTS = 100000000;

    public function admin(User $actor): User
    {
        $admin = User::findOrFail($actor->id);
        abort_unless($admin->isAdmin() && $admin->canAccessPortal() && $admin->email_verified_at, 403,
            'An active verified platform admin must review demo credits.');

        return $admin;
    }

    public function request(User $actor, array $input): WalletTopUpRequest
    {
        $data = Validator::make($input, [
            'amount' => ['required', 'string', 'regex:'.CodMoney::RULE],
            'reason' => ['required', 'string', new ApplicationText('notes', 5, 500)],
            'request_token' => ['required', 'uuid'],
            'balance_cents' => ['prohibited'], 'buyer_id' => ['prohibited'], 'status' => ['prohibited'],
        ])->validate();
        $amount = CodMoney::cents($data['amount']);
        if ($amount < 100 || $amount > self::MAX_TOP_UP_CENTS) {
            throw ValidationException::withMessages(['amount' => 'Request between ₱1.00 and ₱100,000.00 in demo credits.']);
        }
        $token = hash('sha256', $data['request_token']);
        $reason = trim($data['reason']);
        $hash = hash('sha256', json_encode([$amount, $reason], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $amount, $token, $reason, $hash) {
            $buyer = app(BuyerAccessService::class)->requirePortal($actor, lock: true);
            abort_unless($buyer->email_verified_at, 403, 'Verify your sign-in email before requesting credits.');
            if ($prior = WalletTopUpRequest::where('request_token_hash', $token)->first()) {
                abort_unless($prior->buyer_id === $buyer->id && hash_equals($prior->fingerprint, $hash), 409,
                    'This request already saved different credit details.');

                return $prior;
            }
            if (WalletTopUpRequest::where('pending_buyer_id', $buyer->id)->exists()) {
                throw ValidationException::withMessages(['amount' => 'Your current credit request is still awaiting admin review.']);
            }

            return WalletTopUpRequest::create(['reference' => 'WCR-'.Str::upper((string) Str::uuid()),
                'buyer_id' => $buyer->id, 'pending_buyer_id' => $buyer->id, 'amount_cents' => $amount,
                'reason' => $reason, 'status' => 'pending', 'request_token_hash' => $token, 'fingerprint' => $hash]);
        }, 3);
    }

    public function review(User $actor, WalletTopUpRequest $request, array $input): WalletTopUpRequest
    {
        $data = Validator::make($input, [
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'reason' => ['required', 'string', new ApplicationText('notes', 5, 500)],
            'demo_credit_confirmed' => ['accepted'], 'request_token' => ['required', 'uuid'],
            'amount' => ['prohibited'], 'buyer_id' => ['prohibited'], 'balance_cents' => ['prohibited'],
        ])->validate();
        $reason = trim($data['reason']);
        $token = hash('sha256', $data['request_token']);
        $hash = hash('sha256', json_encode([$request->id, $data['decision'], $reason], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $request, $data, $reason, $token, $hash) {
            $admin = $this->admin($actor);
            $buyer = User::whereKey($request->buyer_id)->lockForUpdate()->firstOrFail();
            $locked = WalletTopUpRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($prior = WalletTopUpRequest::where('review_token_hash', $token)->first()) {
                abort_unless($prior->id === $locked->id && $prior->reviewer_id === $admin->id && hash_equals($prior->review_fingerprint, $hash), 409,
                    'This review request already recorded another decision.');

                return $prior;
            }
            abort_unless($locked->status === 'pending', 409, 'This credit request has already been reviewed.');
            $transaction = null;
            if ($data['decision'] === 'approved') {
                app(BuyerAccessService::class)->requirePortal($buyer);
                abort_unless($buyer->email_verified_at && $locked->amount_cents >= 100 && $locked->amount_cents <= self::MAX_TOP_UP_CENTS, 409,
                    'This buyer or the original requested amount is no longer eligible for credits.');
                $ledger = app(WalletLedger::class);
                $account = $ledger->user($buyer);
                $issuance = $ledger->platform('demo_issuance');
                $balance = WalletAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
                abort_if($balance->balance_cents + $locked->amount_cents > self::MAX_GRANTED_BALANCE_CENTS, 409,
                    'This grant would exceed the ₱1,000,000.00 demo wallet limit.');
                $transaction = $ledger->post('credit:'.$locked->id, 'demo_credit', $locked->amount_cents,
                    [$account->id => $locked->amount_cents, $issuance->id => -$locked->amount_cents], $admin, $reason,
                    ['credit_request_id' => $locked->id, 'buyer_id' => $buyer->id, 'demo_only' => true], token: $data['request_token']);
            }
            $locked->fill(['status' => $data['decision'], 'pending_buyer_id' => null, 'reviewer_id' => $admin->id,
                'review_reason' => $reason, 'reviewed_at' => now(), 'wallet_transaction_id' => $transaction?->id,
                'review_token_hash' => $token, 'review_fingerprint' => $hash])->save();
            app(NotificationDeliveryService::class)->record($buyer->id, 'wallet', 'wallet-credit:'.$locked->id,
                ['title' => $transaction ? 'Demo wallet credits added' : 'Demo credit request declined',
                    'body' => $transaction ? 'Your approved demo credits are available in Bagoo Wallet.' : $reason,
                    'target' => 'buyer-wallet']);

            return $locked;
        }, 3);
    }
}
