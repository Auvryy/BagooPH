<?php

namespace App\Services\Orders;

use App\Exceptions\CheckoutException;
use App\Models\Cart;
use App\Models\CheckoutSubmission;
use App\Models\Order;
use App\Models\User;
use App\Services\BuyerAccessService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutSubmissionService
{
    public function issue(User $actor, Cart $cart): string
    {
        $buyer = app(BuyerAccessService::class)->requirePortal($actor);
        abort_unless(Cart::whereKey($cart->id)->where('user_id', $buyer->id)->exists(), 403);

        $nonce = Str::random(64);

        return 'v1.'.$nonce.'.'.$this->signature($buyer, $cart, $nonce);
    }

    public function tokenHash(User $buyer, Cart $cart, mixed $token): string
    {
        Validator::make(['checkout_token' => $token], [
            'checkout_token' => ['bail', 'required', 'string', 'size:132', 'regex:/\Av1\.[A-Za-z0-9]{64}\.[a-f0-9]{64}\z/'],
        ], ['checkout_token.*' => 'Reload checkout before confirming your order.'])->validate();

        $hash = hash('sha256', $token);
        // A retained successful confirmation remains valid through application-key rotation.
        if (CheckoutSubmission::where('token_hash', $hash)->where('buyer_id', $buyer->id)->where('cart_id', $cart->id)->exists()) {
            return $hash;
        }
        [, $nonce, $signature] = explode('.', $token);
        if (! hash_equals($this->signature($buyer, $cart, $nonce), $signature)) {
            throw ValidationException::withMessages(['checkout_token' => 'Reload checkout before confirming your order.']);
        }

        return $hash;
    }

    public function fingerprint(array $data, int $version = 2): string
    {
        if ($version === 1) {
            unset($data['platform_voucher_revision_id'], $data['shop_voucher_revision_ids']);
        }
        ksort($data);

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** @return Collection<int, Order>|null */
    public function replay(User $actor, Cart $cart, string $tokenHash, string $requestHash, array $input = []): ?Collection
    {
        $submission = CheckoutSubmission::where('token_hash', $tokenHash)->first();
        if (! $submission) {
            return null;
        }
        $buyer = app(BuyerAccessService::class)->requirePortal($actor, lock: true);
        abort_unless($submission->buyer_id === $buyer->id && $submission->cart_id === $cart->id, 403);
        if ($submission->fingerprint_version === 1 && empty($input['platform_voucher_revision_id'])
            && ! collect($input['shop_voucher_revision_ids'] ?? [])->filter()->count()) {
            $requestHash = $this->fingerprint($input, 1);
        }
        abort_unless(hash_equals($submission->request_hash, $requestHash), 409,
            'These checkout details differ from your submitted order. Open checkout again to place a different order.');
        $orders = $submission->orders()->where('buyer_id', $buyer->id)->with('delivery', 'items')->get();
        if ($orders->count() !== $submission->order_count || $orders->isEmpty()) {
            throw new CheckoutException('Your original order result is unavailable. Contact support before trying another checkout.');
        }

        return $orders;
    }

    private function signature(User $buyer, Cart $cart, string $nonce): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new CheckoutException('Checkout is temporarily unavailable. Please try again later.');
        }

        return hash_hmac('sha256', 'buyer-checkout:v1:'.$buyer->id.':'.$cart->id.':'.$nonce, $key);
    }
}
