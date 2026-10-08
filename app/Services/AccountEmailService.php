<?php

namespace App\Services;

use App\Models\AccountEmail;
use App\Models\EmailOtp;
use App\Models\User;
use App\Rules\AccountCurrentPassword;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AccountEmailService
{
    public function presentation(User $user): array
    {
        $addresses = $user->accountEmails()->orderByDesc('is_original')->orderBy('id')->get();
        $preferred = $addresses->first(fn (AccountEmail $email) => $email->id === $user->preferred_contact_email_id && $email->verified_at !== null);

        return ['original' => $user->email, 'can_manage' => $user->canAccessPortal(), 'addresses' => $addresses->map(fn (AccountEmail $email) => [
            'id' => $email->id, 'email' => $email->is_original ? $user->email : $email->email,
            'is_original' => $email->is_original, 'verified' => $email->verified_at !== null,
            'preferred' => $preferred ? $preferred->id === $email->id : $email->is_original,
        ])->all()];
    }

    public function recoveryOwner(string $email, bool $lock = false): ?User
    {
        $address = AccountEmail::where('email', strtolower(trim($email)))
            ->where(fn ($query) => $query->where('is_original', true)->orWhereNotNull('verified_at'))->first();
        if (! $address) {
            return null;
        }
        $user = User::whereKey($address->user_id)->whereNull('closed_at')->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if (! $user || ! $user->accountEmails()->whereKey($address->id)->where(fn ($query) => $query->where('is_original', true)->orWhereNotNull('verified_at'))->exists()) {
            return null;
        }

        return $user;
    }

    private function actor(Request $request, bool $lock = false): User
    {
        $user = app(AccountSettingsService::class)->actor($request, $lock);
        if ($lock && ! Hash::check((string) $request->input('current_password'), $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
        }

        return $user;
    }

    private function credentials(Request $request, array $rules = []): array
    {
        $user = $this->actor($request);

        return $request->validate($rules + ['current_password' => ['required', 'string', 'max:4096', new AccountCurrentPassword($user)], 'user_id' => 'prohibited', 'is_original' => 'prohibited', 'verified_at' => 'prohibited']);
    }

    private function address(Request $request): string
    {
        $values = $this->credentials($request, ['email' => ['required', 'string', 'max:255', 'email', 'not_regex:/[\p{C}\s]/u']]);

        return strtolower($values['email']);
    }

    public function send(Request $request): array
    {
        $email = $this->address($request);

        return DB::transaction(function () use ($request, $email) {
            $user = $this->actor($request, lock: true);
            $this->available($user, $email);

            return app(OtpService::class)->sendOtp($email, 'account_email', $user->id);
        }, 3);
    }

    private function available(User $user, string $email): void
    {
        if (AccountEmail::where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'This address already belongs to an account.']);
        }
        if ($user->accountEmails()->where('is_original', false)->count() >= 5) {
            throw ValidationException::withMessages(['email' => 'You can keep up to five additional addresses. Remove one before adding another.']);
        }
    }

    public function confirm(Request $request): void
    {
        $email = $this->address($request);
        $request->validate(['code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/']]);
        $user = $this->actor($request);
        if ($user->accountEmails()->where('email', $email)->where('is_original', false)->whereNotNull('verified_at')->exists()) {
            return;
        }
        // Failed attempts must persist even when the subsequent action is rejected.
        $verified = app(OtpService::class)->verifyOtp($email, $request->input('code'), 'account_email', $user->id);
        if (! $verified['success']) {
            throw ValidationException::withMessages(['code' => $verified['message']]);
        }
        try {
            DB::transaction(function () use ($request, $email, $verified) {
                $user = $this->actor($request, lock: true);
                $this->available($user, $email);
                if (! app(OtpService::class)->validateAndBurnToken($email, $verified['token'], 'account_email', $user->id)) {
                    throw ValidationException::withMessages(['code' => 'This code expired or was already used. Request a new code.']);
                }
                $user->accountEmails()->create(['email' => $email, 'verified_at' => now()]);
            }, 3);
        } catch (QueryException $exception) {
            if (in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true)) {
                throw ValidationException::withMessages(['email' => 'This address already belongs to an account.']);
            }
            throw $exception;
        }
    }

    public function manage(Request $request, AccountEmail $address, bool $remove): void
    {
        $this->credentials($request);
        DB::transaction(function () use ($request, $address, $remove) {
            $user = $this->actor($request, lock: true);
            $address = AccountEmail::whereKey($address->id)->lockForUpdate()->firstOrFail();
            abort_unless($address->user_id === $user->id, 403);
            if ($remove) {
                if ($address->is_original) {
                    throw ValidationException::withMessages(['email' => 'Your original sign-in email stays with your account.']);
                }
                EmailOtp::where('user_id', $user->id)->where('email', $address->email)->delete();
                app(PasswordBroker::class)->getRepository()->delete($user);
                $address->delete();
            } else {
                if ($address->verified_at === null) {
                    throw ValidationException::withMessages(['email' => 'Verify this address before choosing it for contact.']);
                }
                $user->forceFill(['preferred_contact_email_id' => $address->id])->save();
            }
        }, 3);
    }
}
