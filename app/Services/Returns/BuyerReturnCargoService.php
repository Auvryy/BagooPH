<?php

namespace App\Services\Returns;

use App\Models\BuyerReturnCargoReading;
use App\Models\BuyerReturnEvent;
use App\Models\BuyerReturnManifestParcel;
use App\Models\BuyerReturnParcel;
use App\Models\User;
use App\Services\Logistics\CargoHandlingService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class BuyerReturnCargoService
{
    public function available(): bool
    {
        return Schema::hasTable('buyer_return_cargo_readings');
    }

    public function prepare(BuyerReturnParcel $parcel, User $actor, array $input): array
    {
        if (! $this->available()) {
            throw new DomainException('Apply the additive return-handling migration before saving readings.');
        }
        $context = app(BuyerReturnRecoveryService::class)->context($parcel);
        if ($parcel->status !== 'at_hub' || ($parcel->custody['kind'] ?? null) !== 'hub'
            || $parcel->current_hub_id !== ($context['hub_ids'][$context['position']] ?? null)
            || ! isset($context['hub_ids'][$context['position'] + 1])
            || BuyerReturnManifestParcel::where('active_return_parcel_id', $parcel->id)->exists()) {
            throw new DomainException('Measure the actual return at its recorded sending hub before packing or loading.');
        }
        $data = Validator::make($input, app(CargoHandlingService::class)->rules())->validate()['handling'] ?? [];
        $keys = ['weight_kg', 'length_cm', 'width_cm', 'height_cm'];
        if (empty($data['confirmed']) || collect($keys)->contains(fn ($key) => ! isset($data[$key]) || ! is_numeric($data[$key]) || (float) $data[$key] <= 0)) {
            throw new DomainException('Enter and confirm the actual weight and all three outer-package dimensions.');
        }
        $profile = array_map(fn ($value) => round((float) $value, 3), array_intersect_key($data, array_flip($keys)));

        return $profile + ['source' => 'operator_reading', 'actor_id' => $actor->id, 'recorded_at' => now()->toIso8601String(),
            'intake_weight_grams' => $parcel->weight_grams, 'supersedes_reading_id' => $this->reading($parcel)?->id,
            ...($context['recovery_id'] ? ['recovery_id' => $context['recovery_id']] : []),
            'volume_cm3' => (int) ceil($profile['length_cm'] * $profile['width_cm'] * $profile['height_cm']),
            'baggable' => $profile['weight_kg'] <= CargoHandlingService::PARCEL_WEIGHT_KG
                && max($profile['length_cm'], $profile['width_cm'], $profile['height_cm']) <= CargoHandlingService::PARCEL_SIDE_CM];
    }

    public function record(BuyerReturnParcel $parcel, User $actor, BuyerReturnEvent $event, array $profile): BuyerReturnCargoReading
    {
        abort_unless(DB::transactionLevel() > 0 && $event->event_type === 'return-measure'
            && $event->actor_id === $actor->id && $event->buyer_return_case_id === $parcel->buyer_return_case_id, 409);

        return BuyerReturnCargoReading::create(['buyer_return_parcel_id' => $parcel->id, 'buyer_return_event_id' => $event->id,
            'hub_id' => $parcel->current_hub_id, 'route_position' => $parcel->route_position,
            ...(! empty($profile['recovery_id']) ? ['recovery_id' => $profile['recovery_id']] : []),
            'actor_id' => $actor->id, 'profile' => $profile, 'created_at' => now()]);
    }

    public function reading(BuyerReturnParcel $parcel): ?BuyerReturnCargoReading
    {
        $context = app(BuyerReturnRecoveryService::class)->context($parcel);

        return $this->available() ? BuyerReturnCargoReading::where('buyer_return_parcel_id', $parcel->id)
            ->where('hub_id', $context['hub_ids'][$context['position']] ?? 0)->where('route_position', $parcel->route_position)
            ->when(Schema::hasColumn('buyer_return_cargo_readings', 'recovery_id'), fn ($query) => $query->where('recovery_id', $context['recovery_id']))->latest('id')->first() : null;
    }

    public function profile(BuyerReturnParcel $parcel): array
    {
        $reading = $this->reading($parcel);
        $event = $reading ? BuyerReturnEvent::find($reading->buyer_return_event_id) : null;
        $context = app(BuyerReturnRecoveryService::class)->context($parcel);
        if (! $reading || $reading->hub_id !== ($context['hub_ids'][$context['position']] ?? null)
            || $event?->event_type !== 'return-measure' || $event->actor_id !== $reading->actor_id || $event->actor_role !== 'logistics'
            || $event->buyer_return_case_id !== $parcel->buyer_return_case_id || ($event->context['handling'] ?? null) !== $reading->profile
            || $reading->profile['intake_weight_grams'] !== $parcel->weight_grams) {
            throw new DomainException('Retain actual return-package readings at this sending hub before co-loading.');
        }

        return $reading->profile;
    }
}
