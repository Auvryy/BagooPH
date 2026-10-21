<?php

namespace App\Services\AccountSessions;

use App\Models\AccountLoginSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class AccountSessionService
{
    public const SESSION_KEY = 'account_security.session';

    public const LOGIN_ATTRIBUTE = 'account_security.login';

    public const CURRENT_ATTRIBUTE = 'account_security.current';

    public function __construct(private readonly DeviceDescription $devices) {}

    public function fingerprint(User $user): string
    {
        return hash('sha256', $user->getAuthPassword());
    }

    public function actor(Request $request, bool $lock = false): User
    {
        abort_unless($request->user(), 401);

        return User::whereKey($request->user()->id)->whereNull('closed_at')
            ->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
    }

    public function pendingLogin(Login $event): void
    {
        $request = request();
        if ($event->guard !== 'web' || ! $event->user instanceof User || ! $request->hasSession()) {
            return;
        }
        $request->attributes->set(self::LOGIN_ATTRIBUTE, [
            'user_id' => $event->user->id, 'fingerprint' => $this->fingerprint($event->user),
            'version' => (int) $event->user->session_version,
            'remember' => hash('sha256', (string) $event->user->getRememberToken()),
            'method' => Auth::guard('web')->viaRemember() ? 'remembered'
                : ($request->is('auth/google*') ? 'google' : ($request->is('register') ? 'registration' : 'password')),
            'at' => now()->toIso8601String(),
        ]);
    }

    public function currentWeb(Request $request): ?AccountLoginSession
    {
        if (! $request->user() || $request->attributes->has(self::LOGIN_ATTRIBUTE)) {
            return null;
        }
        $user = $this->actor($request);
        $hash = hash('sha256', $request->session()->getId());
        $id = $request->session()->get(self::SESSION_KEY);
        $record = AccountLoginSession::where('user_id', $user->id)
            ->when($id !== null, fn ($query) => $query->where('id', is_string($id) ? $id : ''),
                fn ($query) => $query->where('session_key_hash', $hash))->first();
        if (! $record && $id === null && (int) $user->session_version === 0) {
            $record = DB::transaction(function () use ($request, $hash) {
                $user = $this->actor($request, lock: true);
                abort_unless((int) $user->session_version === 0, 401, 'Please sign in again.');

                return AccountLoginSession::firstOrCreate(['session_key_hash' => $hash], [
                    'user_id' => $user->id, 'channel' => 'web', 'method' => 'existing',
                    'session_version' => 0, 'credential_fingerprint' => $this->fingerprint($user),
                    ...$this->devices->metadata($request), 'last_seen_at' => now(),
                    'expires_at' => now()->addMinutes($this->lifetime()),
                ]);
            }, 3);
        }
        if (! $record || $record->channel !== 'web' || ! hash_equals((string) $record->session_key_hash, $hash) || ! $this->active($record, $user)) {
            return null;
        }
        $request->session()->put(self::SESSION_KEY, $record->id);
        $request->attributes->set(self::CURRENT_ATTRIBUTE, $record->id);

        return $record;
    }

    public function finishWeb(Request $request, ?AccountLoginSession $before): bool
    {
        if (! Auth::guard('web')->check()) {
            return true;
        }
        $login = $request->attributes->get(self::LOGIN_ATTRIBUTE);
        if ($login !== null) {
            return DB::transaction(function () use ($request, $login, $before) {
                $user = $this->actor($request, lock: true);
                if ($user->id !== $login['user_id'] || (int) $user->session_version !== $login['version']
                    || ! hash_equals($login['fingerprint'], $this->fingerprint($user))
                    || ! hash_equals($login['remember'], hash('sha256', (string) $user->getRememberToken()))) {
                    return false;
                }
                if ($before) {
                    AccountLoginSession::whereKey($before->id)->whereNull('revoked_at')->update([
                        'revoked_at' => now(), 'revocation_reason' => 'replaced', 'updated_at' => now(),
                    ]);
                }
                $record = AccountLoginSession::create([
                    'user_id' => $user->id, 'channel' => 'web', 'method' => $login['method'],
                    'session_key_hash' => hash('sha256', $request->session()->getId()),
                    'session_version' => (int) $user->session_version, 'credential_fingerprint' => $this->fingerprint($user),
                    ...$this->devices->metadata($request), 'signed_in_at' => $login['at'],
                    'last_seen_at' => now(), 'expires_at' => now()->addMinutes($this->lifetime()),
                ]);
                $request->session()->put(self::SESSION_KEY, $record->id);
                $request->attributes->set(self::CURRENT_ATTRIBUTE, $record->id);

                return true;
            }, 3);
        }
        if (! $before) {
            return false;
        }

        return DB::transaction(function () use ($request, $before) {
            $user = $this->actor($request, lock: true);
            $record = AccountLoginSession::where('user_id', $user->id)->whereKey($before->id)->lockForUpdate()->first();
            if (! $record || ! $this->active($record, $user)) {
                return false;
            }
            $record->update(['session_key_hash' => hash('sha256', $request->session()->getId()),
                'last_seen_at' => now(), 'expires_at' => now()->addMinutes($this->lifetime())]);

            return true;
        }, 3);
    }

    public function logoutWeb(Request $request, ?User $user): void
    {
        if (! $user || ! $request->hasSession()) {
            return;
        }
        $id = $request->session()->get(self::SESSION_KEY);
        if (is_string($id)) {
            AccountLoginSession::where('user_id', $user->id)->whereKey($id)->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revocation_reason' => 'signed_out', 'updated_at' => now()]);
        }
    }

    public function native(User $user, PersonalAccessToken $token, Request $request, bool $signIn = false): ?AccountLoginSession
    {
        return DB::transaction(function () use ($user, $token, $request, $signIn) {
            $owner = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $token = $owner->tokens()->whereKey($token->id)->first();
            if (! $token || ! str_starts_with($token->name, 'rider:') || ! $token->expires_at || ! $token->expires_at->isFuture()
                || ! hash_equals((string) $token->credential_fingerprint, $this->fingerprint($owner))) {
                return null;
            }
            $record = AccountLoginSession::firstOrCreate(['native_token_id' => $token->id], [
                'user_id' => $owner->id, 'channel' => 'rider', 'method' => $signIn ? 'password' : 'existing',
                'session_version' => (int) $owner->session_version, 'credential_fingerprint' => $this->fingerprint($owner),
                ...$this->devices->metadata($signIn ? $request : new Request, 'rider', substr($token->name, 6)),
                'signed_in_at' => $signIn ? $token->created_at : null,
                'last_seen_at' => $token->last_used_at, 'expires_at' => $token->expires_at,
            ]);
            if (! $this->active($record, $owner)) {
                return null;
            }

            return $record;
        }, 3);
    }

    public function touchNative(User $user, PersonalAccessToken $token, Request $request): bool
    {
        if ($record = $this->native($user, $token, $request)) {
            AccountLoginSession::whereKey($record->id)->whereNull('revoked_at')->update(['last_seen_at' => now(), 'updated_at' => now()]);

            return true;
        }

        return false;
    }

    public function logoutNative(User $user, PersonalAccessToken $token): void
    {
        DB::transaction(function () use ($user, $token) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            AccountLoginSession::where('user_id', $user->id)->where('native_token_id', $token->id)->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revocation_reason' => 'signed_out', 'updated_at' => now()]);
            $user->tokens()->whereKey($token->id)->delete();
        }, 3);
    }

    public function passwordChanged(User $user, ?Request $request = null): void
    {
        DB::transaction(function () use ($user, $request) {
            $owner = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $current = $request?->attributes->get(self::CURRENT_ATTRIBUTE);
            $owner->forceFill(['session_version' => (int) $owner->session_version + 1, 'remember_token' => Str::random(60)])->save();
            $query = AccountLoginSession::where('user_id', $owner->id)->whereNull('revoked_at');
            if (is_string($current)) {
                $query->where('id', '!=', $current);
            }
            $query->update(['revoked_at' => now(), 'revocation_reason' => 'password_changed', 'updated_at' => now()]);
            $owner->tokens()->delete();
            if (is_string($current)) {
                AccountLoginSession::where('user_id', $owner->id)->whereKey($current)->whereNull('revoked_at')
                    ->update(['session_version' => $owner->session_version, 'credential_fingerprint' => $this->fingerprint($owner), 'updated_at' => now()]);
            }
            $user->forceFill(['session_version' => $owner->session_version, 'remember_token' => $owner->remember_token]);
        }, 3);
    }

    public function activeQuery(User $user): Builder
    {
        return AccountLoginSession::where('user_id', $user->id)->whereNull('revoked_at')->where('expires_at', '>', now())
            ->where('session_version', (int) $user->session_version)->where('credential_fingerprint', $this->fingerprint($user))
            ->where(fn ($query) => $query->where('channel', 'web')->orWhereHas('nativeToken', fn ($tokens) => $tokens
                ->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->id)
                ->where('expires_at', '>', now())->where('credential_fingerprint', $this->fingerprint($user))));
    }

    public function active(AccountLoginSession $record, User $user): bool
    {
        if ($record->user_id !== $user->id || $record->revoked_at || ! $record->expires_at->isFuture()
            || $record->session_version !== (int) $user->session_version || ! hash_equals($record->credential_fingerprint, $this->fingerprint($user))) {
            return false;
        }
        if ($record->channel === 'rider') {
            $token = $record->nativeToken;

            return $token && $token->tokenable_type === $user->getMorphClass() && (int) $token->tokenable_id === $user->id
                && $token->expires_at?->isFuture() && hash_equals((string) $token->credential_fingerprint, $this->fingerprint($user));
        }

        return $record->channel === 'web';
    }

    public function importExisting(User $user, Request $request): void
    {
        if ((int) $user->session_version === 0 && config('session.driver') === 'database') {
            DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)->where('last_activity', '>', now()->subMinutes($this->lifetime())->timestamp)
                ->select(['id', 'ip_address', 'user_agent', 'last_activity'])->orderBy('id')->chunkById(100, function ($rows) use ($user) {
                    foreach ($rows as $row) {
                        $seen = CarbonImmutable::createFromTimestampUTC($row->last_activity);
                        $metadata = $this->devices->metadata(new Request(server: ['HTTP_USER_AGENT' => $row->user_agent, 'REMOTE_ADDR' => $row->ip_address]));
                        AccountLoginSession::firstOrCreate(['session_key_hash' => hash('sha256', $row->id)], [
                            'user_id' => $user->id, 'channel' => 'web', 'method' => 'existing', 'session_version' => 0,
                            'credential_fingerprint' => $this->fingerprint($user), ...$metadata,
                            'last_seen_at' => $seen, 'expires_at' => $seen->addMinutes($this->lifetime()),
                        ]);
                    }
                });
        }
        $user->tokens()->where('name', 'like', 'rider:%')->where('expires_at', '>', now())
            ->where('credential_fingerprint', $this->fingerprint($user))->orderBy('id')->chunkById(100, function ($tokens) use ($user, $request) {
                foreach ($tokens as $token) {
                    $this->native($user, $token, $request);
                }
            });
    }

    public function page(Request $request): array
    {
        $data = $request->validate(['sessions_page' => 'sometimes|integer|between:1,1000000', 'history_page' => 'sometimes|integer|between:1,1000000']);
        $user = $this->actor($request);
        $this->importExisting($user, $request);
        $current = $request->session()->get(self::SESSION_KEY);
        $relations = ['nativeToken' => fn ($tokens) => $tokens->select(['id', 'tokenable_type', 'tokenable_id', 'expires_at', 'credential_fingerprint'])];
        $active = $this->activeQuery($user)->with($relations)->orderByDesc('last_seen_at')->orderBy('id')
            ->paginate(12, ['*'], 'sessions_page', $data['sessions_page'] ?? 1)->withPath('/account/security')->appends($data)
            ->through(fn ($record) => $this->present($record, $user, $current));
        $history = AccountLoginSession::where('user_id', $user->id)->whereNotNull('signed_in_at')
            ->where('signed_in_at', '>=', now()->subDays(config('account_sessions.history_days', 90)))
            ->with($relations)->orderByDesc('signed_in_at')->orderByDesc('id')
            ->paginate(12, ['*'], 'history_page', $data['history_page'] ?? 1)->withPath('/account/security')->appends($data)
            ->through(fn ($record) => $this->present($record, $user, $current));
        $latest = AccountLoginSession::where('user_id', $user->id)->whereNotNull('signed_in_at')->latest('signed_in_at')->first();
        $user->loadMissing(['courierProfile.company', 'courierProfile.hub']);

        return ['sessions' => $active, 'signIns' => $history, 'lastSignIn' => $latest ? $this->present($latest, $user, $current) : null,
            'currentSessionId' => $current, 'sessionOwnerId' => $user->id, 'canConfirmByEmail' => $user->hasVerifiedEmail(),
            'historyDays' => (int) config('account_sessions.history_days', 90),
            'canUsePortal' => $user->canAccessPortal(), 'isOnline' => (bool) $user->courierProfile?->is_available,
            'scope' => ['company' => $user->courierProfile?->company?->name, 'hub' => $user->courierProfile?->hub?->name,
                'hub_code' => $user->courierProfile?->hub?->code, 'barangay' => $user->courierProfile?->assigned_barangay]];
    }

    public function present(AccountLoginSession $record, User $user, ?string $current): array
    {
        $active = $this->active($record, $user);
        try {
            $network = $this->devices->network($record->network_address);
        } catch (DecryptException) {
            $network = null;
        }

        return ['id' => $record->id, 'channel' => $record->channel, 'method' => $record->method,
            'device' => $record->device_label, 'device_type' => $record->device_type, 'network' => $network,
            'signed_in_at' => $record->signed_in_at?->toIso8601String(), 'last_seen_at' => $record->last_seen_at?->toIso8601String(),
            'ended_at' => $record->revoked_at?->toIso8601String(), 'active' => $active, 'current' => $record->id === $current,
            'status' => $active ? 'active' : ($record->revoked_at ? 'signed_out' : ($record->expires_at->isPast() ? 'expired' : 'ended'))];
    }

    public function minimiseOldMetadata(): int
    {
        $cutoff = now()->subDays(config('account_sessions.history_days', 90));

        return AccountLoginSession::whereNull('metadata_cleared_at')
            ->where(fn ($query) => $query->where('revoked_at', '<', $cutoff)->orWhere('expires_at', '<', $cutoff))
            ->update(['network_address' => null, 'user_agent' => null, 'device_label' => 'Earlier session', 'metadata_cleared_at' => now(), 'updated_at' => now()]);
    }

    private function lifetime(): int
    {
        return max(1, (int) config('session.lifetime', 120));
    }
}
