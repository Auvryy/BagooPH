<?php

namespace Tests\Concerns;

use App\Models\Cart;
use App\Models\User;
use App\Services\Orders\CheckoutSubmissionService;

trait InteractsWithCheckoutSubmission
{
    private function checkoutToken(User $buyer, ?Cart $cart = null): string
    {
        $cart ??= Cart::where('user_id', $buyer->id)->firstOrFail();

        return app(CheckoutSubmissionService::class)->issue($buyer, $cart);
    }
}
