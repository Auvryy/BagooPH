<?php

namespace App\Services\Wallet;

use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletEntry;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WalletLedger
{
    public function balance(User $user): ?int
    {
        // One statement reads the cache and journal from the same database snapshot.
        $account = WalletAccount::where('user_id', $user->id)->withSum('entries as recorded_cents', 'delta_cents')->first();
        if (! $account) {
            return 0;
        }

        return $account->account_key === 'user:'.$user->id && $account->kind === 'user'
            && $account->balance_cents === (int) $account->recorded_cents ? $account->balance_cents : null;
    }

    public function user(User $user): WalletAccount
    {
        $account = WalletAccount::firstOrCreate(['account_key' => 'user:'.$user->id], ['user_id' => $user->id, 'kind' => 'user', 'balance_cents' => 0]);
        abort_unless($account->user_id === $user->id && $account->kind === 'user', 409, 'This wallet ownership needs review.');

        return $account;
    }

    public function platform(string $kind): WalletAccount
    {
        abort_unless(in_array($kind, ['demo_issuance', 'escrow', 'commission', 'shipping', 'promotion'], true), 409);

        $account = WalletAccount::firstOrCreate(['account_key' => 'platform:'.$kind], ['kind' => $kind, 'balance_cents' => 0]);
        abort_unless($account->user_id === null && $account->kind === $kind, 409, 'This virtual platform account needs review.');

        return $account;
    }

    /** @param array<int, int> $deltas Account IDs mapped to signed integer centavos. */
    public function post(string $key, string $kind, int $amount, array $deltas, ?User $actor, string $reason,
        array $context = [], ?int $orderId = null, ?int $returnId = null, ?string $token = null): WalletTransaction
    {
        abort_unless(DB::transactionLevel() > 0 && $amount >= 0 && array_sum($deltas) === 0, 409, 'The wallet journal must balance inside its original transaction.');
        ksort($deltas);
        $hash = hash('sha256', json_encode([$kind, $amount, $deltas, $actor?->id, $reason, $context, $orderId, $returnId], JSON_THROW_ON_ERROR));
        $tokenHash = $token === null ? null : hash('sha256', $token);
        $prior = WalletTransaction::where('business_key', $key)->first();
        if ($prior) {
            abort_unless(hash_equals($prior->fingerprint, $hash), 409, 'This wallet action already recorded different details.');

            return $prior;
        }
        abort_if($tokenHash && WalletTransaction::where('request_token_hash', $tokenHash)->exists(), 409, 'This wallet request was already used.');
        $accounts = WalletAccount::whereIn('id', array_keys($deltas))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        abort_unless($accounts->count() === count($deltas), 409, 'A wallet account is unavailable.');
        foreach ($deltas as $id => $delta) {
            abort_unless(is_int($delta), 409);
            $account = $accounts[$id];
            $recorded = (int) $account->entries()->sum('delta_cents');
            abort_unless($account->balance_cents === $recorded, 409, 'This wallet balance needs a review of its transaction history.');
            abort_unless($recorded + $delta >= 0 || in_array($account->kind, ['demo_issuance', 'promotion'], true), 409, 'There are not enough wallet credits for this payment.');
        }
        $transaction = WalletTransaction::create(['reference' => 'WLT-'.Str::upper((string) Str::uuid()),
            'business_key' => $key, 'kind' => $kind, 'actor_id' => $actor?->id, 'order_id' => $orderId,
            'buyer_return_case_id' => $returnId, 'amount_cents' => $amount, 'request_token_hash' => $tokenHash,
            'fingerprint' => $hash, 'reason' => $reason, 'context' => $context, 'created_at' => now()]);
        foreach ($deltas as $id => $delta) {
            if ($delta === 0) {
                continue;
            }
            $account = $accounts[$id];
            $after = $account->balance_cents + $delta;
            $updated = WalletAccount::whereKey($id)->where('balance_cents', $account->balance_cents)->update(['balance_cents' => $after, 'updated_at' => now()]);
            abort_unless($updated === 1, 409, 'The wallet changed. Reload its saved balance before retrying.');
            WalletEntry::create(['wallet_transaction_id' => $transaction->id, 'wallet_account_id' => $id,
                'delta_cents' => $delta, 'balance_after_cents' => $after, 'created_at' => now()]);
        }

        return $transaction;
    }
}
