<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Product;
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
        ])->validate();

        return [
            'products' => fn () => $this->paginate(Product::with(['shop:id,name', 'category:id,name']), $filters, $path,
                ['id', 'name', 'shop_id', 'category_id', 'stock', 'price', 'status', 'compliance_restricted']),
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
