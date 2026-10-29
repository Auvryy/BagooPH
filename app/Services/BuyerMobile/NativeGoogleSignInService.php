<?php

namespace App\Services\BuyerMobile;

use App\Models\User;
use App\Services\ApplicationValidationService;
use App\Services\BuyerAccessService;
use App\Services\GoogleRegistrationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class NativeGoogleSignInService
{
    public function exchange(Request $request, array $identity, bool $remember): array
    {
        try {
            $user = DB::transaction(function () use ($request, $identity) {
                $linked = User::where('google_id', $identity['sub'])->lockForUpdate()->first();
                if ($request->user()) {
                    if (! $linked || $linked->id !== $request->user()->id) {
                        $this->conflict('ACCOUNT_SWITCH_REQUIRED', 'Sign out before using a different Google account.');
                    }

                    return $this->eligible($linked);
                }
                if ($linked) {
                    return $this->eligible($linked);
                }
                $existing = User::where('role', 'buyer')->whereRaw('LOWER(TRIM(email)) = ?', [$identity['email']])->lockForUpdate()->first();
                if (! $existing) {
                    return null;
                }
                $this->eligible($existing);
                if ($existing->google_id !== null) {
                    $this->conflict('GOOGLE_IDENTITY_CONFLICT', 'Use the Google account already linked to your Bagoo account, or sign in with email.');
                }
                if (! $request->filled('account_password')) {
                    $this->conflict('GOOGLE_ACCOUNT_LINK_REQUIRED', 'Enter your existing Bagoo password to connect this Google account, or sign in with email.');
                }
                if (! Hash::check($request->string('account_password')->toString(), $existing->password)) {
                    throw ValidationException::withMessages(['account_password' => 'Your Bagoo password is incorrect. Try again or sign in with email.']);
                }
                $existing->google_id = $identity['sub'];
                $existing->email_verified_at ??= now();
                $existing->save();

                return $existing;
            });
        } catch (QueryException $exception) {
            if (! in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true)) {
                throw $exception;
            }
            $this->conflict('GOOGLE_IDENTITY_CONFLICT', 'This Google account changed during sign-in. Start again or use email sign-in.');
        }

        if ($user) {
            $this->authenticate($request, $user, $remember);

            return ['authenticated' => true];
        }
        $applications = app(ApplicationValidationService::class);
        $values = $applications->registrationValues(['name' => $identity['name'], 'email' => $identity['email']], 'buyer');
        Validator::make($values, ['email' => $applications->rules('buyer')['email']])->validate();
        $request->session()->put(GoogleRegistrationService::NATIVE_PENDING, [
            'identity' => $values, 'google_id' => $identity['sub'], 'avatar' => $identity['avatar'],
            'nonce' => Str::random(40), 'expires_at' => min(now()->addMinutes(10)->timestamp, $identity['expires_at']),
            'remember' => $remember,
        ]);

        return ['registration_required' => true];
    }

    public function authenticate(Request $request, User $user, bool $remember): void
    {
        $this->eligible($user);
        if (! $request->user()) {
            Auth::login($user, $remember);
        }
        $request->session()->forget(GoogleRegistrationService::NATIVE_PENDING);
        $request->session()->regenerate();
    }

    private function eligible(User $user): User
    {
        if (! app(BuyerAccessService::class)->canViewHolding($user)) {
            throw new HttpException(403, 'This account cannot use buyer Google sign-in. Use its own sign-in page or contact support.');
        }

        return $user;
    }

    private function conflict(string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), 409));
    }
}
