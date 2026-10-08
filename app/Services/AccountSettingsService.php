<?php

namespace App\Services;

use App\Models\User;
use App\Rules\AccountCurrentPassword;
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
                'password' => ['required', 'string', Password::min(12)->max(128), 'confirmed'],
            ]);
            $user->update(['password' => $values['password']]);
            $user->tokens()->delete();
        }, 3);
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
