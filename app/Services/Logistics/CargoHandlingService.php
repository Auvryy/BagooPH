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

    public const BAG_PRESETS = [
        'standard' => [
            'key' => 'standard',
            'label' => 'Standard Sack (50 parcels · 25 kg · 0.30 m³)',
            'max_count' => self::BAG_PARCELS,
            'max_weight_kg' => self::BAG_WEIGHT_KG,
            'max_volume_cm3' => self::BAG_VOLUME_CM3,
        ],
        'compact' => [
            'key' => 'compact',
            'label' => 'Small Pouch Sack (80 parcels · 15 kg · 0.20 m³)',
            'max_count' => 80,
            'max_weight_kg' => 15,
            'max_volume_cm3' => 200000,
        ],
        'bulk' => [
            'key' => 'bulk',
            'label' => 'Bulk / Cage Bag (120 parcels · 50 kg · 0.60 m³)',
            'max_count' => 120,
            'max_weight_kg' => 50,
            'max_volume_cm3' => 600000,
        ],
    ];

    public function presets(): array
    {
        return array_values(self::BAG_PRESETS);
    }

    public function resolvePreset(array $input): array
    {
        $preset = isset($input['capacity_preset']) && is_string($input['capacity_preset'])
            ? trim($input['capacity_preset'])
            : 'standard';
        if ($preset === '' || $preset === 'standard') {
            $base = self::BAG_PRESETS['standard'];

            return [
                'capacity_preset' => 'standard',
                'max_count' => $base['max_count'],
                'max_weight_kg' => (float) $base['max_weight_kg'],
                'max_volume_cm3' => $base['max_volume_cm3'],
            ];
        }
        if (isset(self::BAG_PRESETS[$preset])) {
            $base = self::BAG_PRESETS[$preset];

            return [
                'capacity_preset' => $preset,
                'max_count' => $base['max_count'],
                'max_weight_kg' => (float) $base['max_weight_kg'],
                'max_volume_cm3' => $base['max_volume_cm3'],
            ];
        }
        if ($preset !== 'custom') {
            throw new DomainException('Choose a valid Mother Bag capacity preset or custom limit.');
        }

        $maxCount = isset($input['max_count']) && is_numeric($input['max_count']) ? (int) $input['max_count'] : self::BAG_PARCELS;
        $maxWeight = isset($input['max_weight_kg']) && is_numeric($input['max_weight_kg']) ? round((float) $input['max_weight_kg'], 3) : (float) self::BAG_WEIGHT_KG;
        $maxVolumeCm3 = isset($input['max_volume_cm3']) && is_numeric($input['max_volume_cm3'])
            ? (int) $input['max_volume_cm3']
            : (isset($input['max_volume_m3']) && is_numeric($input['max_volume_m3'])
                ? (int) round((float) $input['max_volume_m3'] * 1000000)
                : self::BAG_VOLUME_CM3);

        if ($maxCount < 1 || $maxCount > 500) {
            throw new DomainException('Custom parcel limit must be between 1 and 500 parcels.');
        }
        if ($maxWeight < 1 || $maxWeight > 200) {
            throw new DomainException('Custom weight limit must be between 1 and 200 kg.');
        }
        if ($maxVolumeCm3 < 10000 || $maxVolumeCm3 > 2000000) {
            throw new DomainException('Custom volume limit must be between 0.01 and 2.00 cubic metres.');
        }

        return [
            'capacity_preset' => 'custom',
            'max_count' => $maxCount,
            'max_weight_kg' => $maxWeight,
            'max_volume_cm3' => $maxVolumeCm3,
        ];
    }

    public function limits(MotherBag $bag): array
    {
        return [
            'preset' => $bag->capacity_preset ?: 'standard',
            'max_count' => (int) ($bag->max_count ?? self::BAG_PARCELS),
            'max_weight_kg' => (float) ($bag->max_weight_kg ?? self::BAG_WEIGHT_KG),
            'max_volume_cm3' => (int) ($bag->max_volume_cm3 ?? self::BAG_VOLUME_CM3),
        ];
    }

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
        $limits = $this->limits($bag);

        return ['count' => $members->count(), 'weight_kg' => $known ? round($members->sum(fn ($m) => $m->handling_snapshot['weight_kg']), 3) : null,
            'volume_cm3' => $known ? $members->sum(fn ($m) => $m->handling_snapshot['volume_cm3']) : null,
            'preset' => $limits['preset'],
            'max_count' => $limits['max_count'], 'max_weight_kg' => $limits['max_weight_kg'], 'max_volume_cm3' => $limits['max_volume_cm3']];
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
        if ($capacity['count'] >= $capacity['max_count'] || $capacity['weight_kg'] + $profile['weight_kg'] > $capacity['max_weight_kg']
            || $capacity['volume_cm3'] + $profile['volume_cm3'] > $capacity['max_volume_cm3']) {
            $volM3 = number_format($capacity['max_volume_cm3'] / 1000000, 2, '.', '');
            $weight = rtrim(rtrim(number_format($capacity['max_weight_kg'], 3, '.', ''), '0'), '.');
            throw new DomainException("This bag would exceed {$capacity['max_count']} parcels, {$weight} kg or {$volM3} cubic metres. Use another open bag.");
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
