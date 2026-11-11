<?php

namespace App\Services\Logistics;

use App\Models\User;
use App\Services\AccountSessions\AccountSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LogisticsAccountService
{
    public function hubs(User $user)
    {
        return app(LogisticsEligibilityService::class)->accessibleHubs($user)->orderBy('id')->get()
            ->filter(fn ($hub) => app(LogisticsEligibilityService::class)->canScan($user, $hub))->values();
    }

    public function assertAccessible(User $user): void
    {
        abort_unless($user->isLogistics() && $user->canAccessPortal(), 403, 'An active, activated logistics operator account is required.');
        abort_if($this->hubs($user)->isEmpty(), 403, 'No active scanning assignment is available. Ask your company owner to review your hub assignment.');
    }

    public function resource(User $user): array
    {
        return ['user' => $user->only(['id', 'name', 'email', 'role']),
            'hubs' => $this->hubs($user)->map->only(['id', 'name', 'code', 'logistics_company_id'])->all()];
    }

    public function session(User $user, string $device, Request $request): array
    {
        $fingerprint = hash('sha256', $user->getAuthPassword());

        return DB::transaction(function () use ($user, $device, $request, $fingerprint) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->password_login_enabled && hash_equals($fingerprint, hash('sha256', $user->getAuthPassword())), 401, 'Account access changed. Sign in again.');
            $this->assertAccessible($user);
            $expires = now()->addHours(12);
            $issued = $user->createToken('logistics:'.$device, ['logistics:account', 'logistics:scan', 'logistics:logout'], $expires);
            $issued->accessToken->forceFill(['credential_fingerprint' => hash('sha256', $user->getAuthPassword())])->save();
            abort_unless(app(AccountSessionService::class)->native($user, $issued->accessToken, $request, signIn: true), 401, 'Could not open a work session. Sign in again.');

            return ['token' => $issued->plainTextToken, 'expires_at' => $expires->toIso8601String(), ...$this->resource($user)];
        });
    }
}
