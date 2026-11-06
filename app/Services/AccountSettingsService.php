<?php

namespace App\Services;

use App\Models\User;
use App\Rules\AccountCurrentPassword;
use App\Services\AccountSessions\AccountSessionService;
use App\Services\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AccountSettingsService
{
    public function actor(Request $request, bool $lock = false, bool $requirePortal = true): User
    {
        abort_unless($request->user(), 401);
        $user = User::whereKey($request->user()->id)->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
        abort_unless($user->closed_at === null && (! $requirePortal || $user->canAccessPortal()), 403);
        if ($ability = $request->attributes->get('rider_settings_ability')) {
            abort_unless($user->isEligibleCourier(), 403);
            $token = app(RiderAccountService::class)->assertSettingsToken($request, $user, $ability, $lock);
            $user->withAccessToken($token);
        }
        $request->setUserResolver(fn () => $user);

        return $user;
    }

    public function changePassword(Request $request): void
    {
        DB::transaction(function () use ($request) {
            $user = $this->actor($request, lock: true, requirePortal: $request->attributes->has('rider_settings_ability'));
            if (! $user->hasVerifiedEmail()) {
                throw ValidationException::withMessages(['email' => 'Verify your email address before changing your password.']);
            }
            $values = $request->validate([
                'current_password' => ['required', 'string', 'max:4096', new AccountCurrentPassword($user)],
                'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            ]);
            $user->update(['password' => $values['password']]);
            app(AccountSessionService::class)->passwordChanged($user, $request);
        }, 3);
    }

    public function sendGooglePasswordCode(Request $request): array
    {
        $user = $this->googlePasswordUser($request);

        return app(OtpService::class)->sendOtp($user->email, 'google_password_setup', $user->id, $user->role);
    }

    public function setGooglePassword(Request $request): void
    {
        $values = $request->validate([
            'code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            'email' => ['prohibited'], 'user_id' => ['prohibited'], 'role' => ['prohibited'], 'token' => ['prohibited'],
        ]);
        $user = $this->googlePasswordUser($request);
        $verified = app(OtpService::class)->verifyOtp($user->email, $values['code'], 'google_password_setup', $user->id, $user->role);
        if (! $verified['success']) {
            throw ValidationException::withMessages(['code' => $verified['message']]);
        }

        DB::transaction(function () use ($request, $user, $values, $verified): void {
            $locked = $this->googlePasswordUser($request, lock: true);
            abort_unless($locked->id === $user->id, 403);
            if (! app(OtpService::class)->validateAndBurnToken($locked->email, $verified['token'], 'google_password_setup', $locked->id, $locked->role)) {
                throw ValidationException::withMessages(['code' => 'This code expired or was already used. Request a new code.']);
            }
            $locked->update(['password' => $values['password'], 'password_login_enabled' => true]);
            app(AccountSessionService::class)->passwordChanged($locked, $request);
        }, 3);
    }

    private function googlePasswordUser(Request $request, bool $lock = false): User
    {
        $user = $this->actor($request, lock: $lock, requirePortal: false);
        abort_unless(is_string($user->google_id) && $user->google_id !== '', 403, 'This password setup is only available for Google-linked accounts.');
        if (! $user->hasVerifiedEmail()) {
            throw ValidationException::withMessages(['email' => 'Verify your email address before setting a password.']);
        }
        if ($user->password_login_enabled) {
            throw ValidationException::withMessages(['password' => 'Email and password sign-in is already enabled. Use your current password to make a change.']);
        }

        return $user;
    }

    public function presentation(User $user): array
    {
        $correction = null;
        if ($user->closed_at === null && $user->isKycApproved() && (! $user->isSeller() || $user->shop()->exists())) {
            $correction = app(IdentityCorrectionService::class)->form($user, $user);
        }

        return ['identityCorrection' => $correction ? ['subject' => $correction, 'categories' => app(MasterCategoryService::class)->choices()] : null,
            'emailSettings' => app(AccountEmailService::class)->presentation($user)];
    }
}
