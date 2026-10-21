<?php

namespace App\Services;

use App\Models\User;
use App\Services\AccountSessions\AccountSessionService;
use App\Services\Logistics\RiderCompanyOfferService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;

class RiderAccountService
{
    public const SETTINGS_ABILITIES = ['rider:settings:read', 'rider:settings:profile', 'rider:settings:password', 'rider:settings:emails'];

    public const OPERATIONS_ABILITIES = ['rider:operations:read', 'rider:operations:work', 'rider:operations:messages', 'rider:operations:notifications', 'rider:operations:cash'];

    public const HANDOVER_ABILITY = 'rider:operations:handover';

    public const COMPANY_ABILITIES = ['rider:company:read', 'rider:company:write'];

    public function companyAvailable(): bool
    {
        return $this->settingsAvailable() && app(RiderCompanyOfferService::class)->available() && Schema::hasTable('rider_commands')
            && Schema::hasTable('notification_deliveries')
            && Schema::hasColumn('personal_access_tokens', 'credential_fingerprint');
    }

    public function companyEligible(User $user): bool
    {
        return $user->isEligibleCourier() && $user->email_verified_at !== null;
    }

    public function hasCompanyAbility(User $user, string $ability): bool
    {
        $token = $user->currentAccessToken();

        return $token instanceof PersonalAccessToken && in_array($ability, $token->abilities, true);
    }

    public function assertCompanyToken(Request $request, User $user, string $ability, bool $lock = false): PersonalAccessToken
    {
        $token = $this->assertSettingsToken($request, $user, $ability, $lock, 'Sign in again to enable company joining.');
        abort_unless(in_array($ability, self::COMPANY_ABILITIES, true) && in_array($ability, $token->abilities, true), 403,
            'Sign in again to enable company joining.');

        return $token;
    }

    public function handoverAvailable(): bool
    {
        return $this->operationsAvailable() && collect(['handover_issues', 'handover_issue_events', 'handover_recovery_grants', 'handover_manifest_receipts'])
            ->every(fn ($table) => Schema::hasTable($table));
    }

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
        if ($user->isCourier() && $user->closed_at === null && in_array($user->status, ['suspended', 'inactive'], true)) {
            $state = $user->status === 'suspended' ? 'suspended' : 'deactivated';
            throw new HttpResponseException(response()->json(['message' => 'Your rider account is '.$state.'. Sign in on the website to view Account status and request a review.',
                'account_status_url' => '/account/status'], 403));
        }
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
            ...($this->operationsAvailable() ? ['operations_api_version' => 1] : []),
            ...($this->handoverAvailable() ? ['handover_api_version' => 1] : []), 'name' => $user->name, 'email' => $user->email,
            ...($this->companyAvailable() ? ['company_api_version' => 1] : []),
            'email_verified' => $user->email_verified_at !== null, 'role' => 'courier',
            'status' => $user->status, 'kyc_status' => $user->kyc_status, 'kyc_feedback' => $user->kyc_feedback,
            'can_access_portal' => $user->canAccessPortal(), 'access_state' => $user->canAccessPortal() ? 'approved' : 'holding'];
    }

    public function session(User $user, string $device): array
    {
        $fingerprint = hash('sha256', $user->getAuthPassword());
        $version = (int) $user->session_version;

        return DB::transaction(function () use ($user, $device, $fingerprint, $version) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! hash_equals($fingerprint, hash('sha256', $user->getAuthPassword())) || $version !== (int) $user->session_version) {
                throw new HttpResponseException(response()->json(['message' => 'Your account access changed. Please sign in again.'], 401));
            }
            $this->assertAccessible($user);
            $expires = now()->addMinutes(max(1, min(10080, (int) config('rider_auth.token_minutes'))));
            $issued = $user->createToken('rider:'.$device, ['rider:account', 'rider:logout', ...($this->settingsAvailable() ? self::SETTINGS_ABILITIES : []),
                ...($this->operationsAvailable() ? self::OPERATIONS_ABILITIES : []),
                ...($this->companyAvailable() && $this->companyEligible($user) ? self::COMPANY_ABILITIES : []),
                ...($this->handoverAvailable() && $user->isEligibleCourier() && $user->canAccessPortal() ? [self::HANDOVER_ABILITY] : [])], $expires);
            $issued->accessToken->forceFill(['credential_fingerprint' => hash('sha256', $user->getAuthPassword())])->save();
            app(AccountSessionService::class)->native($user, $issued->accessToken, request(), signIn: true);

            return ['token' => $issued->plainTextToken, 'token_type' => 'Bearer',
                'expires_at' => $expires->toIso8601String(), 'user' => $this->resource($user)];
        }, 3);
    }
}
