<?php

namespace App\Services\Commerce;

use App\Models\Shop;
use Illuminate\Database\Eloquent\Builder;

class CatalogueLocationService
{
    public function __construct(private readonly PhilippineAddressCatalog $catalog) {}

    public function areas(): array
    {
        return $this->catalog->catalogueAreas();
    }

    public function filter(Builder $products, string $province): Builder
    {
        // Shop city is part of its reviewed pickup location. Account residence is not.
        $cities = Shop::query()->whereNotNull('city')->distinct()->pluck('city')
            ->filter(fn ($city) => $this->catalog->provinceForUniqueCity($city) === $province)->values()->all();

        return $products->whereHas('shop', fn (Builder $shops) => $shops->where(function (Builder $query) use ($cities) {
            if ($cities === []) {
                $query->whereRaw('1 = 0');
            }
            foreach (array_chunk($cities, 500) as $chunk) {
                $query->orWhereIn('shops.city', $chunk);
            }
        }));
    }
}
