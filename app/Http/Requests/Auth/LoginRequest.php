<?php

namespace App\Http\Requests\Auth;

use App\Services\AccountIdentityService;
use App\Services\Finance\CodCashService;
use App\Services\Logistics\HandoverRecoveryService;
use App\Services\Logistics\RestrictedCustodyRecoveryService;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $credentials = $this->only('email', 'password');
        $identities = app(AccountIdentityService::class);
        $identity = $identities->signInAccount($this, $credentials['email']);
        $credentials['email'] = $identity?->email ?? $credentials['email'];
        $credentials['id'] = $identity?->id ?? 0;
        if (! Auth::attempt($credentials, $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => ($role = $identities->selectedRole($this))
                    ? 'Email or password is incorrect for your '.AccountIdentityService::label($role).' account.'
                    : 'Email or password is incorrect. Please try again.',
            ]);
        }

        $user = Auth::user();

        if ($this->is('custody-recovery/sign-in') && (app(RestrictedCustodyRecoveryService::class)->ownGrants($user)
            || app(HandoverRecoveryService::class)->restrictedOwn($user))) {
            RateLimiter::clear($this->throttleKey());

            return;
        }
        if ($this->is('cash-handover/sign-in') && ($user->isCourier() || $user->isLogistics())
            && $user->closed_at === null && $user->email_verified_at !== null
            && app(CodCashService::class)->scoped($user)->exists()) {
            RateLimiter::clear($this->throttleKey());

            return;
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
