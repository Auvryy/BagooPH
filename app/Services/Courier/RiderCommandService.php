<?php

namespace App\Services\Courier;

use App\Models\RiderCommand;
use App\Models\User;
use App\Services\RiderAccountService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RiderCommandService
{
    public function execute(Request $request, string $action, string $resource, array $intent, callable $write): array
    {
        $key = $this->key($request->header('Idempotency-Key', ''));
        $fingerprint = hash('sha256', json_encode([$action, $resource, $this->sorted($intent)], JSON_THROW_ON_ERROR));
        $actorId = $request->user()->id;
        try {
            return DB::transaction(function () use ($request, $action, $resource, $key, $fingerprint, $actorId, $write) {
                $existing = RiderCommand::where('actor_id', $actorId)->where('idempotency_key', $key)->lockForUpdate()->first();
                if ($existing) {
                    $this->authorize($request);

                    return $this->replay($existing, $fingerprint);
                }
                // Insert first to serialize the same intent; domain writers keep their own order/parcel/network lock order.
                // The unfinished row is never committed or advertised as a successful command.
                DB::table('rider_commands')->insert(['actor_id' => $actorId, 'idempotency_key' => $key,
                    'action' => $action, 'resource' => $resource, 'fingerprint' => $fingerprint,
                    'result' => '{}', 'recorded_at' => now(), 'expires_at' => now()->addDays(7)]);
                $result = $write();
                // Fresh token/account validation shares the domain transaction. Revocation rolls back every effect.
                $this->authorize($request);
                DB::table('rider_commands')->where('actor_id', $actorId)->where('idempotency_key', $key)
                    ->update(['result' => json_encode($result, JSON_THROW_ON_ERROR), 'recorded_at' => now()]);

                return $this->present(RiderCommand::where('actor_id', $actorId)->where('idempotency_key', $key)->firstOrFail(), false);
            });
        } catch (UniqueConstraintViolationException $exception) {
            // A competing transaction committed the same actor/key while our insert waited.
            return DB::transaction(function () use ($request, $actorId, $key, $fingerprint) {
                $this->authorize($request);

                return $this->replay(RiderCommand::where('actor_id', $actorId)->where('idempotency_key', $key)->firstOrFail(), $fingerprint);
            });
        }
    }

    public function owned(Request $request, string $key): array
    {
        $key = $this->key($key);
        $command = RiderCommand::where('actor_id', $request->user()->id)->where('idempotency_key', $key)->first();
        if (! $command) {
            RiderApiInput::error('COMMAND_UNKNOWN', 'No committed result is visible. This does not prove the request failed; refresh before retrying the same intent.', 404);
        }

        return $this->present($command, false);
    }

    private function authorize(Request $request): void
    {
        $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
        app(RiderAccountService::class)->assertAccessible($user);
        abort_unless($user->isEligibleCourier(), 403);
        app(RiderAccountService::class)->assertSettingsToken($request, $user,
            $request->attributes->get('rider_operations_ability'), true, 'Sign in again to enable rider operations.');
    }

    private function replay(RiderCommand $command, string $fingerprint): array
    {
        if (! hash_equals($command->fingerprint, $fingerprint)) {
            RiderApiInput::error('IDEMPOTENCY_CONFLICT', 'This key already recorded a different action or payload.', 409);
        }
        if (now()->greaterThanOrEqualTo($command->expires_at)) {
            RiderApiInput::error('COMMAND_EXPIRED', 'This retained key is outside the seven-day retry window. Read its result and refresh the resource.', 409);
        }

        return $this->present($command, true);
    }

    private function present(RiderCommand $command, bool $replayed): array
    {
        return ['idempotency_key' => $command->idempotency_key, 'action' => $command->action,
            'resource' => $command->resource, 'state' => 'committed', 'result' => $command->result,
            'recorded_at' => RiderApiInput::time($command->recorded_at), 'expires_at' => RiderApiInput::time($command->expires_at),
            'retry_expired' => now()->greaterThanOrEqualTo($command->expires_at), 'replayed' => $replayed];
    }

    private function key(string $key): string
    {
        Validator::make(['idempotency_key' => $key], ['idempotency_key' => ['required', 'uuid', 'regex:/\A[a-f0-9-]{36}\z/']])->validate();

        return $key;
    }

    private function sorted(array $input): array
    {
        ksort($input);
        foreach ($input as &$value) {
            if (is_array($value)) {
                $value = $this->sorted($value);
            }
        }

        return $input;
    }
}
