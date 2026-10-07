<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;

class RiderAccountService
{
    public function assertAccessible(User $user): void
    {
        if (! $user->isCourier() || $user->closed_at !== null
            || ! in_array($user->status, ['active', 'pending_approval'], true)
            || ! in_array($user->kyc_status, ['approved', 'verified', 'pending_approval', 'rejected'], true)
            || ! $user->hasEligibleBirthDate()) {
            throw new HttpResponseException(response()->json(['message' => 'This account cannot access the rider application.'], 403));
        }
    }

    public function resource(User $user): array
    {
        return ['id' => (string) $user->id, 'name' => $user->name, 'email' => $user->email,
            'email_verified' => $user->email_verified_at !== null, 'role' => 'courier',
            'status' => $user->status, 'kyc_status' => $user->kyc_status, 'kyc_feedback' => $user->kyc_feedback,
            'can_access_portal' => $user->canAccessPortal(), 'access_state' => $user->canAccessPortal() ? 'approved' : 'holding'];
    }

    public function session(User $user, string $device): array
    {
        $this->assertAccessible($user);
        $expires = now()->addMinutes(max(1, min(10080, (int) config('rider_auth.token_minutes'))));
        $issued = $user->createToken('rider:'.$device, ['rider:account', 'rider:logout'], $expires);
        $issued->accessToken->forceFill(['credential_fingerprint' => hash('sha256', $user->getAuthPassword())])->save();

        return ['token' => $issued->plainTextToken, 'token_type' => 'Bearer',
            'expires_at' => $expires->toIso8601String(), 'user' => $this->resource($user)];
    }
}
