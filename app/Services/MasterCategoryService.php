<?php

namespace App\Services;

use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class MasterCategoryService
{
    public const NAMES = [
        'Pet Supplies',
        'Electronics and Gadgets',
        "Women's Apparel",
        "Men's Apparel",
        'Kids and Baby',
        'Home and Garden',
        'Sports and Outdoors',
        'Health and Beauty',
        'Books and Media',
        'Food and Gourmet',
        'Automotive & Motorcycle (Group 9)',
        'Furniture and Office Equipment',
        'Jewelry and Watches',
        'Office and School Supplies (Group 12)',
    ];

    public const ISSUE = 'Choose an active master category from Bagoo\'s 14 categories for your original shop before approval.';

    public function activeRoots(): Builder
    {
        return Category::query()->whereNull('parent_id')->where('is_active', true)
            ->whereIn('name', self::NAMES)
            ->whereNotExists(function ($query) {
                // Ambiguous legacy roots need review, not a guessed category assignment.
                $query->selectRaw('1')->from('categories as duplicate_roots')
                    ->whereNull('duplicate_roots.parent_id')
                    ->whereColumn('duplicate_roots.name', 'categories.name')
                    ->whereColumn('duplicate_roots.id', '<>', 'categories.id');
            });
    }

    public function choices(): array
    {
        return $this->activeRoots()->get(['id', 'name'])
            ->sortBy(fn (Category $category) => array_search($category->name, self::NAMES, true))
            ->values()->toArray();
    }

    public function snapshot(?int $id, bool $lock = false): ?array
    {
        if ($id === null) {
            return null;
        }
        $query = Category::whereKey($id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $category = $query->first();

        return $category ? [
            ...$category->only(['id', 'name', 'parent_id', 'is_active']),
            'eligible' => $this->activeRoots()->whereKey($id)->exists(),
        ] : null;
    }

    public function requireEligible(int $id): void
    {
        if (! ($this->snapshot($id, lock: true)['eligible'] ?? false)) {
            throw ValidationException::withMessages(['root_category_id' => self::ISSUE]);
        }
    }
}
