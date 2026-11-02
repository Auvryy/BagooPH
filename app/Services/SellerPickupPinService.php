<?php

namespace App\Services;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SellerPickupPinService
{
    public function token(Shop $shop): string
    {
        return hash_hmac('sha256', json_encode($shop->only(['id', 'user_id', 'address', 'city', 'pickup_province', 'pickup_postal_code', 'pickup_latitude', 'pickup_longitude']), JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function update(User $actor, Shop $subject, array $input): void
    {
        $coordinate = fn (int $max) => ['bail', 'required', 'numeric', 'regex:/\A-?[0-9]+(?:\.[0-9]{1,7})?\z/', "between:-{$max},{$max}"];
        $data = Validator::make($input, ['latitude' => $coordinate(90), 'longitude' => $coordinate(180),
            'location_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/']])->validate();

        DB::transaction(function () use ($actor, $subject, $data) {
            $owner = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($owner->isSeller() && $owner->canAccessPortal(), 403);
            $shop = Shop::whereKey($subject->id)->where('user_id', $owner->id)->lockForUpdate()->firstOrFail();
            if (! hash_equals($this->token($shop), $data['location_token'])) {
                throw ValidationException::withMessages(['location_token' => 'The shop location changed. Reload before saving your pin.']);
            }
            $shop->update(['pickup_latitude' => $data['latitude'], 'pickup_longitude' => $data['longitude']]);
        }, 3);
    }
}
