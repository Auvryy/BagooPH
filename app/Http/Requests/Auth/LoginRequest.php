<?php

namespace App\Http\Requests\Auth;

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

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        $user = Auth::user();
        $host = $this->getHost();
        $appDomain = env('APP_DOMAIN', 'bagooph.shop');
        $roleMismatch = null;

        if (str_starts_with($host, 'seller.')) {
            if ($user->role !== 'seller') {
                $roleMismatch = 'Role mismatch: Non-seller accounts cannot access the Seller Merchant Cockpit.';
            }
        } elseif (str_starts_with($host, 'courier.')) {
            if ($user->role !== 'courier') {
                $roleMismatch = 'Role mismatch: Non-courier accounts cannot access the Courier Fleet Dispatch portal.';
            }
        } elseif (str_starts_with($host, 'hub.')) {
            if (! in_array($user->role, ['logistics', 'admin'], true)) {
                $roleMismatch = 'Role mismatch: Non-logistics accounts cannot access the Logistics Sorting Center.';
            }
        } elseif (str_starts_with($host, 'admin.')) {
            if ($user->role !== 'admin') {
                $roleMismatch = 'Role mismatch: Non-admin accounts cannot access the Platform Governance portal.';
            }
        } elseif ($host === $appDomain || $host === 'www.' . $appDomain) {
            if ($user->role !== 'buyer') {
                $roleMismatch = 'Role mismatch: Non-buyer accounts cannot access the Buyer Marketplace portal.';
            }
        }

        if ($roleMismatch !== null) {
            Auth::logout();
            if ($this->hasSession()) {
                $this->session()->invalidate();
                $this->session()->regenerateToken();
            }
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => $roleMismatch,
            ]);
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
