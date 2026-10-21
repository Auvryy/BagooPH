<?php

namespace App\Services\AccountSessions;

use App\Models\AccountLoginSession;
use App\Models\AccountSessionAction;
use App\Models\EmailOtp;
use App\Models\User;
use App\Rules\AccountCurrentPassword;
use App\Services\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountSessionActions
{
    public const OTP_PURPOSE = 'account_sessions';

    public function __construct(private readonly AccountSessionService $sessions, private readonly OtpService $otp) {}

    public function sendCode(Request $request): array
    {
        $request->validate(array_fill_keys(['email', 'user_id', 'role', 'purpose'], 'prohibited'));

        return DB::transaction(function () use ($request) {
            $user = $this->sessions->actor($request, lock: true);
            if (! $user->hasVerifiedEmail()) {
                throw ValidationException::withMessages(['code' => 'Verify your sign-in email before using an email code. You can confirm with your current password instead.']);
            }

            return $this->otp->sendOtp($user->email, self::OTP_PURPOSE, $user->id, $user->role);
        }, 3);
    }

    public function revoke(Request $request, ?string $target): AccountSessionAction
    {
        $data = $request->validate([
            'request_token' => ['required', 'string', 'uuid'],
            'confirmation_method' => ['required', 'string', Rule::in(['password', 'email'])],
            'current_password' => ['required_if:confirmation_method,password', 'nullable', 'string', 'max:4096'],
            'code' => ['required_if:confirmation_method,email', 'nullable', 'string', 'regex:/\A[0-9]{6}\z/'],
            ...array_fill_keys(['user_id', 'role', 'email', 'session_id', 'token_id', 'device', 'ip_address'], 'prohibited'),
        ]);
        $owner = $this->sessions->actor($request);
        $current = $request->attributes->get(AccountSessionService::CURRENT_ATTRIBUTE);
        abort_unless(is_string($current), 401, 'Sign in again before managing sessions.');
        $target = $target === null ? null : strtolower($target);
        if ($target === $current) {
            throw ValidationException::withMessages(['session' => 'Use Sign out on this session to end your current sign-in.']);
        }
        if ($target !== null) {
            AccountLoginSession::where('user_id', $owner->id)->whereKey($target)->firstOrFail();
        }
        $key = hash('sha256', strtolower($data['request_token']));
        $fingerprint = hash('sha256', json_encode([$owner->id, $current, $target, $data['confirmation_method']], JSON_THROW_ON_ERROR));
        $prior = AccountSessionAction::where('user_id', $owner->id)->where('request_token_hash', $key)->first();
        if ($prior) {
            return $this->replay($prior, $fingerprint);
        }

        // Failed email attempts commit independently of the later sign-out transaction.
        $verified = null;
        if ($data['confirmation_method'] === 'email') {
            if (! $owner->hasVerifiedEmail()) {
                throw ValidationException::withMessages(['code' => 'Verify your sign-in email before confirming by email.']);
            }
            $verified = $this->verifyEmailCode($owner, $data['code']);
            if (! $verified['success']) {
                throw ValidationException::withMessages(['code' => $verified['message']]);
            }
        }

        return DB::transaction(function () use ($request, $data, $current, $target, $key, $fingerprint, $verified) {
            $user = $this->sessions->actor($request, lock: true);
            $currentRecord = AccountLoginSession::where('user_id', $user->id)->whereKey($current)->lockForUpdate()->first();
            abort_unless($currentRecord && $this->sessions->active($currentRecord, $user)
                && hash_equals((string) $currentRecord->session_key_hash, hash('sha256', $request->session()->getId())), 401, 'Sign in again before managing sessions.');
            if ($prior = AccountSessionAction::where('user_id', $user->id)->where('request_token_hash', $key)->first()) {
                return $this->replay($prior, $fingerprint);
            }
            if ($data['confirmation_method'] === 'password') {
                Validator::make($data, ['current_password' => [new AccountCurrentPassword($user)]])->validate();
            } elseif (! $user->hasVerifiedEmail() || ! $this->otp->validateAndBurnToken($user->email, $verified['token'], self::OTP_PURPOSE, $user->id, $user->role)) {
                throw ValidationException::withMessages(['code' => 'This confirmation expired or was already used. Request a new code.']);
            }

            $this->sessions->importExisting($user, $request);
            if ($target !== null) {
                AccountLoginSession::where('user_id', $user->id)->whereKey($target)->lockForUpdate()->firstOrFail();
            }
            $targets = $this->sessions->activeQuery($user)->where('id', '!=', $current)
                ->when($target !== null, fn ($query) => $query->whereKey($target));
            $count = (clone $targets)->count();
            $action = AccountSessionAction::create([
                'user_id' => $user->id, 'actor_session_id' => $current, 'request_token_hash' => $key,
                'request_fingerprint' => $fingerprint, 'action' => $target === null ? 'others' : 'one',
                'target_session_id' => $target, 'confirmation_method' => $data['confirmation_method'], 'affected_count' => $count,
            ]);
            $targets->update(['revoked_at' => now(), 'revocation_reason' => 'remote_sign_out',
                'revocation_action_id' => $action->id, 'updated_at' => now()]);
            if ($target === null) {
                $user->tokens()->where('name', 'like', 'rider:%')->delete();
                $user->forceFill(['session_version' => (int) $user->session_version + 1, 'remember_token' => Str::random(60)])->save();
                $currentRecord->update(['session_version' => $user->session_version]);
            } elseif ($count > 0) {
                $user->tokens()->whereIn('id', AccountLoginSession::where('revocation_action_id', $action->id)->whereNotNull('native_token_id')->select('native_token_id'))->delete();
                // Invalidate saved sign-ins too; existing unrelated sessions stay authenticated.
                $user->forceFill(['remember_token' => Str::random(60)])->save();
            }

            return $action;
        }, 3);
    }

    private function replay(AccountSessionAction $action, string $fingerprint): AccountSessionAction
    {
        abort_unless(hash_equals($action->request_fingerprint, $fingerprint), 409, 'This sign-out request was already used for a different selection. Reload login activity.');

        return $action;
    }

    private function verifyEmailCode(User $owner, string $code): array
    {
        return DB::transaction(function () use ($owner, $code) {
            $prepared = EmailOtp::forEmail($owner->email, self::OTP_PURPOSE)->where('user_id', $owner->id)
                ->where('role', $owner->role)->latest('id')->lockForUpdate()->first();
            // A failed sign-out save can leave verified proof unspent; retry that proof until its original expiry.
            if ($prepared?->verified_at && $prepared->token && $prepared->expires_at->isFuture() && $prepared->attempts < 5) {
                $prepared->increment('attempts');
                if (Hash::check($code, $prepared->code_hash)) {
                    return ['success' => true, 'token' => $prepared->token];
                }

                return ['success' => false, 'message' => 'The code is incorrect. Check the six numbers in your email.'];
            }

            return $this->otp->verifyOtp($owner->email, $code, self::OTP_PURPOSE, $owner->id, $owner->role);
        }, 3);
    }
}
