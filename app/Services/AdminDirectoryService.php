<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdminDirectoryService
{
    public function users(array $input, string $path): array
    {
        $filters = Validator::make($input, [
            'search' => 'nullable|string|max:100',
            'role' => ['nullable', Rule::enum(UserRole::class)],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000', 'regex:/^[1-9][0-9]*$/D'],
        ])->validate();

        return [
            'users' => function () use ($filters, $path) {
                $query = User::query();
                if (! empty($filters['role'])) {
                    $query->where('role', $filters['role']);
                }
                if (($filters['search'] ?? '') !== '') {
                    $search = '%'.$filters['search'].'%';
                    $query->where(fn ($row) => $row->where('name', 'like', $search)->orWhere('email', 'like', $search));
                }

                // The directory needs no shop, identity evidence or full account payload.
                return $this->paginate($query, $filters, $path, ['id', 'name', 'email', 'role', 'phone', 'city', 'status', 'kyc_status']);
            },
            'filters' => ['search' => $filters['search'] ?? '', 'role' => $filters['role'] ?? ''],
            'activityBaseUrl' => $path,
        ];
    }

    public function products(array $input, string $path): array
    {
        $filters = Validator::make($input, [
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000', 'regex:/^[1-9][0-9]*$/D'],
            'search' => ['nullable', 'string', 'max:100'],
            'shop' => ['nullable', 'integer', 'min:1'],
            'category' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:active,draft'],
            'compliance' => ['nullable', 'in:allowed,restricted'],
            'stock' => ['nullable', 'in:out,low'],
        ])->validate();
        // Filters narrow the list so admins never page through every listing to find one.
        $query = Product::with(['shop:id,name', 'category:id,name'])
            ->when(filled($filters['search'] ?? null), fn (Builder $q) => $q->where('name', 'like', '%'.addcslashes(trim($filters['search']), '%_\\').'%'))
            ->when(filled($filters['shop'] ?? null), fn (Builder $q) => $q->where('shop_id', (int) $filters['shop']))
            ->when(filled($filters['category'] ?? null), fn (Builder $q) => $q->where('category_id', (int) $filters['category']))
            ->when(filled($filters['status'] ?? null), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(($filters['compliance'] ?? null) === 'restricted', fn (Builder $q) => $q->where('compliance_restricted', true))
            ->when(($filters['compliance'] ?? null) === 'allowed', fn (Builder $q) => $q->where('compliance_restricted', false))
            ->when(($filters['stock'] ?? null) === 'out', fn (Builder $q) => $q->where('stock', '<=', 0))
            ->when(($filters['stock'] ?? null) === 'low', fn (Builder $q) => $q->whereBetween('stock', [1, 5]));

        return [
            'products' => fn () => $this->paginate($query, $filters, $path,
                ['id', 'name', 'shop_id', 'category_id', 'stock', 'price', 'status', 'compliance_restricted']),
            'filters' => array_intersect_key($filters, array_flip(['search', 'shop', 'category', 'status', 'compliance', 'stock'])),
            'shops' => fn () => Shop::query()->orderBy('name')->get(['id', 'name']),
            'categories' => fn () => Category::query()->orderBy('name')->get(['id', 'name']),
            'moderationBaseUrl' => $path,
        ];
    }

    private function paginate(Builder $query, array $filters, string $path, array $columns): LengthAwarePaginator
    {
        $total = (clone $query)->count();
        $page = min((int) ($filters['page'] ?? 1), max(1, (int) ceil($total / 15)));
        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->forPage($page, 15)->get($columns);

        return (new LengthAwarePaginator($rows, $total, 15, $page, ['path' => $path]))
            ->appends(array_filter($filters, fn ($value, $key) => $key !== 'page' && $value !== null && $value !== '', ARRAY_FILTER_USE_BOTH));
    }
}
