<?php

namespace App\Services\Logistics;

use App\Models\Delivery;
use App\Models\LogisticsManifest;
use App\Models\MotherBag;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class CargoHandlingService
{
    public const BAG_WEIGHT_KG = 25;

    public const BAG_VOLUME_CM3 = 300000;

    public const BAG_PARCELS = 50;

    public const PARCEL_WEIGHT_KG = 15;

    public const PARCEL_SIDE_CM = 60;

    public function available(): bool
    {
        return Schema::hasColumn('mother_bags', 'packing_policy_version');
    }

    public function rules(): array
    {
        return ['handling' => 'sometimes|array:weight_kg,length_cm,width_cm,height_cm,confirmed',
            'handling.weight_kg' => 'bail|nullable|numeric|min:0.001|max:10000|decimal:0,3',
            'handling.length_cm' => 'bail|nullable|numeric|min:0.001|max:10000|decimal:0,3',
            'handling.width_cm' => 'bail|nullable|numeric|min:0.001|max:10000|decimal:0,3',
            'handling.height_cm' => 'bail|nullable|numeric|min:0.001|max:10000|decimal:0,3',
            'handling.confirmed' => 'sometimes|boolean'];
    }

    public function profile(Delivery $parcel, User $actor, array $input = []): array
    {
        $data = Validator::make($input, $this->rules())->validate()['handling'] ?? [];
        $keys = ['weight_kg', 'length_cm', 'width_cm', 'height_cm'];
        if (collect($keys)->contains(fn ($key) => isset($data[$key]) && $data[$key] !== '')) {
            foreach ($keys as $key) {
                if (! isset($data[$key]) || ! is_numeric($data[$key]) || (float) $data[$key] <= 0) {
                    throw new DomainException('Enter the weight and all three outer-package dimensions.');
                }
            }
            if (empty($data['confirmed'])) {
                throw new DomainException('Confirm that you checked this actual outer package before saving its handling readings.');
            }
            $profile = array_map(fn ($value) => round((float) $value, 3), array_intersect_key($data, array_flip($keys)));
            $profile += ['source' => 'operator_reading', 'actor_id' => $actor->id, 'recorded_at' => now()->toIso8601String()];
        } else {
            $measurements = $parcel->order->shipping_quote['measurements'] ?? [];
            if (count($measurements) !== 1 || (int) ($measurements[0]['quantity'] ?? 0) !== 1) {
                throw new DomainException('Measure this outer package first. Individual item sizes do not establish a multi-item parcel size.');
            }
            $unit = $measurements[0];
            $profile = ['weight_kg' => $unit['unit_weight_kg'] ?? null, 'length_cm' => $unit['length_cm'] ?? null,
                'width_cm' => $unit['width_cm'] ?? null, 'height_cm' => $unit['height_cm'] ?? null];
            foreach ($keys as $key) {
                if (! is_numeric($profile[$key]) || (float) $profile[$key] <= 0) {
                    throw new DomainException('This purchase has incomplete package dimensions. Enter actual outer-package handling readings.');
                }
                $profile[$key] = (float) $profile[$key];
            }
            $profile += ['source' => 'purchased_declaration'];
        }
        $profile['volume_cm3'] = (int) ceil($profile['length_cm'] * $profile['width_cm'] * $profile['height_cm']);
        $profile['baggable'] = $profile['weight_kg'] <= self::PARCEL_WEIGHT_KG
            && max($profile['length_cm'], $profile['width_cm'], $profile['height_cm']) <= self::PARCEL_SIDE_CM;

        return $profile;
    }

    public function capacity(MotherBag $bag): array
    {
        $members = $bag->parcels()->whereNull('removed_at')->get();
        if (Schema::hasTable('mother_bag_return_parcels')) {
            $members = $members->concat($bag->returnParcels()->whereNull('removed_at')->get());
        }
        $known = $members->every(fn ($member) => $member->handling_snapshot !== null);

        return ['count' => $members->count(), 'weight_kg' => $known ? round($members->sum(fn ($m) => $m->handling_snapshot['weight_kg']), 3) : null,
            'volume_cm3' => $known ? $members->sum(fn ($m) => $m->handling_snapshot['volume_cm3']) : null,
            'max_count' => self::BAG_PARCELS, 'max_weight_kg' => self::BAG_WEIGHT_KG, 'max_volume_cm3' => self::BAG_VOLUME_CM3];
    }

    public function assertFits(MotherBag $bag, array $profile): void
    {
        if (! $profile['baggable']) {
            throw new DomainException('This parcel exceeds 15 kg or a 60 cm side. Load it individually as loose cargo in the manifest.');
        }
        $capacity = $this->capacity($bag);
        if ($capacity['weight_kg'] === null || $capacity['volume_cm3'] === null) {
            throw new DomainException('Recheck the existing bag members before adding cargo with unknown capacity.');
        }
        if ($capacity['count'] >= self::BAG_PARCELS || $capacity['weight_kg'] + $profile['weight_kg'] > self::BAG_WEIGHT_KG
            || $capacity['volume_cm3'] + $profile['volume_cm3'] > self::BAG_VOLUME_CM3) {
            throw new DomainException('This bag would exceed 50 parcels, 25 kg or 0.30 cubic metres. Use another open bag.');
        }
    }

    public function manifestCapacity(LogisticsManifest $manifest): array
    {
        $members = $manifest->parcels()->where('included', true)->get();
        $known = $members->every(fn ($m) => $m->handling_snapshot !== null);

        return ['weight_kg' => $known ? round($members->sum(fn ($m) => $m->handling_snapshot['weight_kg']), 3) : null,
            'max_weight_kg' => (float) $manifest->vehicle->capacity_kg,
            'loose_count' => $members->where('cargo_mode', 'loose')->count(), 'count' => $members->count()];
    }

    public function assertManifestCapacity(LogisticsManifest $manifest): void
    {
        if (! $this->available() || ! $manifest->parcels()->whereNotNull('cargo_mode')->exists()) {
            return; // Existing untyped history retains its original transport contract.
        }
        $capacity = $this->manifestCapacity($manifest);
        if ($capacity['weight_kg'] === null || $capacity['max_weight_kg'] <= 0 || $capacity['weight_kg'] > $capacity['max_weight_kg']) {
            throw new DomainException('The retained cargo weight exceeds the vehicle capacity or includes unknown readings. Recheck the draft load.');
        }
    }
}
