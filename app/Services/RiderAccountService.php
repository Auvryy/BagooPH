<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;

class RiderAccountService
{
    public const SETTINGS_ABILITIES = ['rider:settings:read', 'rider:settings:profile', 'rider:settings:password', 'rider:settings:emails'];

    public const OPERATIONS_ABILITIES = ['rider:operations:read', 'rider:operations:work'];

    public function operationsAvailable(): bool
    {
        return $this->settingsAvailable() && Schema::hasTable('rider_commands') && Schema::hasTable('delivery_checkpoints') && Schema::hasTable('cod_accounts');
    }

    public function settingsAvailable(): bool
    {
        return Schema::hasColumn('users', 'contact_settings_version') && Schema::hasTable('account_emails');
    }

    public function findToken(?string $plain): ?PersonalAccessToken
    {
        if (! $plain) {
            return null;
        }
        if (str_contains($plain, '|')) {
            $id = explode('|', $plain, 2)[0];
            if (! preg_match('/\A[1-9][0-9]*\z/', $id) || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                return null;
            }
        }

        return PersonalAccessToken::findToken($plain);
    }

    public function assertSettingsToken(Request $request, User $user, string $ability, bool $lock = false, string $authorityMessage = 'Sign in again to enable native account settings.'): PersonalAccessToken
    {
        $token = $this->findToken($request->bearerToken());
        if ($lock && $token) {
            $token = PersonalAccessToken::whereKey($token->id)->where('token', $token->token)->lockForUpdate()->first();
        }
        if (! $token || $token->tokenable_type !== $user->getMorphClass() || (string) $token->tokenable_id !== (string) $user->id
            || ! $token->expires_at || now()->greaterThanOrEqualTo($token->expires_at)
            || ! hash_equals((string) $token->getAttribute('credential_fingerprint'), hash('sha256', $user->getAuthPassword()))) {
            throw new HttpResponseException(response()->json(['message' => 'Your session expired. Please sign in again.'], 401));
        }
        if (! $token->can('rider:account') || ! $token->can($ability)) {
            throw new HttpResponseException(response()->json(['message' => $authorityMessage], 403));
        }

        return $token;
    }

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
        return ['id' => (string) $user->id, ...($this->settingsAvailable() ? ['settings_api_version' => 1] : []),
            ...($this->operationsAvailable() ? ['operations_api_version' => 1] : []), 'name' => $user->name, 'email' => $user->email,
            'email_verified' => $user->email_verified_at !== null, 'role' => 'courier',
            'status' => $user->status, 'kyc_status' => $user->kyc_status, 'kyc_feedback' => $user->kyc_feedback,
            'can_access_portal' => $user->canAccessPortal(), 'access_state' => $user->canAccessPortal() ? 'approved' : 'holding'];
    }

    public function session(User $user, string $device): array
    {
        $this->assertAccessible($user);
        $expires = now()->addMinutes(max(1, min(10080, (int) config('rider_auth.token_minutes'))));
        $issued = $user->createToken('rider:'.$device, ['rider:account', 'rider:logout', ...($this->settingsAvailable() ? self::SETTINGS_ABILITIES : []),
            ...($this->operationsAvailable() ? self::OPERATIONS_ABILITIES : [])], $expires);
        $issued->accessToken->forceFill(['credential_fingerprint' => hash('sha256', $user->getAuthPassword())])->save();

        return ['token' => $issued->plainTextToken, 'token_type' => 'Bearer',
            'expires_at' => $expires->toIso8601String(), 'user' => $this->resource($user)];
    }
}
