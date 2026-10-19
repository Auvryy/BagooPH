<?php

namespace App\Services\Commerce;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PhilippineAddressCatalog
{
    private static ?array $index = null;

    private array $barangays = [];

    private ?array $cityAreas = null;

    public function catalogueAreas(): array
    {
        $areas = [];
        foreach ($this->index()['provinces'] as $code => $province) {
            $areas[] = ['value' => (string) $code, 'label' => $province['name']];
        }
        usort($areas, fn ($left, $right) => strnatcasecmp($left['label'], $right['label']));

        return $areas;
    }

    public function provinceForUniqueCity(string $name): ?string
    {
        if ($this->cityAreas === null) {
            $this->cityAreas = ['exact' => [], 'short' => []];
            foreach ($this->index()['cities'] as $city) {
                $this->cityAreas['exact'][$this->key($city['name'])][] = $city['province'];
                $this->cityAreas['short'][$this->key($city['name'], true)][] = $city['province'];
            }
        }
        $matches = $this->cityAreas['exact'][$this->key($name)] ?? $this->cityAreas['short'][$this->key($name, true)] ?? [];
        // A repeated municipality name is never assigned a province by guesswork.
        return count($matches) === 1 ? (string) $matches[0] : null;
    }

    private function index(): array
    {
        return self::$index ??= json_decode(file_get_contents(resource_path('data/philippine-addresses/index.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function regionName(?string $code): ?string
    {
        return $code !== null ? ($this->index()['regions'][$code] ?? null) : null;
    }

    private function key(string $name, bool $city = false): string
    {
        $name = mb_strtolower(trim(Str::ascii($name)));
        $name = preg_replace('/\s+/', ' ', $name);

        return $city ? preg_replace('/^(?:city|municipality) of | (?:city|municipality)$/', '', $name) : $name;
    }

    private function find(array $items, string $name, bool $city = false): ?string
    {
        $exact = array_keys(array_filter($items, fn ($item) => $this->key($item['name']) === $this->key($name)));
        if (count($exact) === 1) {
            return (string) $exact[0];
        }
        $matches = array_keys(array_filter($items, fn ($item) => $this->key($item['name'], $city) === $this->key($name, $city)));

        return count($matches) === 1 ? (string) $matches[0] : null;
    }

    private function provinceName(string $value): string
    {
        return in_array($this->key($value), ['ncr', 'national capital region', 'national capital region (ncr)'], true) ? 'Metro Manila' : $value;
    }

    private function provinces(string $region): array
    {
        return array_filter($this->index()['provinces'], fn ($item) => $item['region'] === $region);
    }

    private function cities(string $province): array
    {
        return array_filter($this->index()['cities'], fn ($item) => $item['province'] === $province);
    }

    public function regionFor(string $province, string $city = ''): string
    {
        $matches = [];
        foreach ($this->index()['regions'] as $region => $name) {
            $provinceId = $this->find($this->provinces((string) $region), $this->provinceName($province));
            if ($provinceId && ($city === '' || $this->find($this->cities($provinceId), $city, true))) {
                $matches[] = (string) $region;
            }
        }
        if (count($matches) !== 1) {
            throw ValidationException::withMessages(['city' => 'Choose a city or municipality that belongs to the selected province or area.']);
        }

        return $matches[0];
    }

    public function options(array $input = []): array
    {
        $region = $input['region'] ?? '';
        if ($region === '' && ! empty($input['province'])) {
            $region = $this->regionFor($input['province'], $input['city'] ?? '');
        }
        if ($region !== '' && ! isset($this->index()['regions'][$region])) {
            throw ValidationException::withMessages(['region' => 'Choose a region from the list.']);
        }
        $provinces = $region !== '' ? $this->provinces($region) : [];
        $province = ! empty($input['province']) ? $this->find($provinces, $this->provinceName($input['province'])) : null;
        if ($provinces && count($provinces) === 1 && empty($input['province'])) {
            $province = (string) array_key_first($provinces);
        }
        if (! empty($input['province']) && ! $province) {
            throw ValidationException::withMessages(['province' => 'Choose a province or area that belongs to this region.']);
        }
        $cities = $province ? $this->cities($province) : [];
        $city = ! empty($input['city']) ? $this->find($cities, $input['city'], true) : null;
        if (! empty($input['city']) && ! $city) {
            throw ValidationException::withMessages(['city' => 'Choose a city or municipality that belongs to this province or area.']);
        }
        $barangays = [];
        if ($city) {
            $this->barangays[$region] ??= json_decode(file_get_contents(resource_path('data/philippine-addresses/barangays-'.$region.'.json')), true, flags: JSON_THROW_ON_ERROR);
            $barangays = $this->barangays[$region][$city];
        }
        $choices = function (array $items, bool $codes = false): array {
            $items = array_map(fn ($code, $item) => ['value' => $codes ? (string) $code : (is_array($item) ? $item['name'] : $item), 'label' => is_array($item) ? $item['name'] : $item], array_keys($items), array_values($items));
            usort($items, fn ($left, $right) => strnatcasecmp($left['label'], $right['label']));

            return $items;
        };

        return ['regions' => $choices($this->index()['regions'], true), 'provinces' => $choices($provinces),
            'cities' => $choices($cities), 'barangays' => $choices($barangays),
            'resolved' => ['region' => $region, 'province' => $province ? $provinces[$province]['name'] : '', 'city' => $city ? $cities[$city]['name'] : '']];
    }

    public function validate(array $data): array
    {
        $options = $this->options($data);
        $barangays = array_column($options['barangays'], 'value');
        $matching = array_values(array_filter($barangays, fn ($name) => $this->key($name) === $this->key($data['barangay'])));
        if ($matching === []) {
            throw ValidationException::withMessages(['barangay' => 'Choose a barangay that belongs to the selected city or municipality.']);
        }

        return [...$data, ...$options['resolved'], 'barangay' => $matching[0]];
    }
}
