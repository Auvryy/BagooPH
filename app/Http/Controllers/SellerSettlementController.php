<?php

namespace App\Http\Controllers;

use App\Services\Finance\SellerSettlementService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SellerSettlementController extends Controller
{
    public function __construct(private readonly SellerSettlementService $settlements) {}

    public function index(Request $request)
    {
        $actor = $this->settlements->current($request->user());
        $filters = $request->validate(['status' => ['nullable', Rule::in(['pending', 'eligible', 'authorized', 'settled'])],
            'page' => 'sometimes|integer|min:1|max:100000', 'q' => 'nullable|string|max:100']);
        $query = $this->settlements->scoped($actor)->with(['items.shop.user', 'delivery', 'codAccount', 'commissionLedger', 'sellerSettlement']);
        if (! empty($filters['q'])) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']).'%';
            $query->whereRaw("order_number LIKE ? ESCAPE '!'", [$term]);
        }
        $rows = $query->orderByDesc('id')->get()->map(fn ($order) => $this->settlements->present($order));
        if (! empty($filters['status'])) {
            $rows = $rows->where('status', $filters['status'])->values();
        }
        $page = (int) ($filters['page'] ?? 1);
        $payload = ['records' => new LengthAwarePaginator($rows->forPage($page, 12)->values(), $rows->count(), 12, $page,
            ['path' => $request->url(), 'query' => $request->query()]), 'filters' => $filters,
            'can_release' => $actor->isAdmin()];

        return $request->expectsJson() ? response()->json($payload)->header('Cache-Control', 'private, no-store')
            : Inertia::render('Finance/Settlements', $payload);
    }

    public function show(Request $request, int $order)
    {
        $actor = $this->settlements->current($request->user());
        $source = $this->settlements->scoped($actor)->whereKey($order)->firstOrFail();
        $payload = ['record' => $this->settlements->present($source, history: true), 'can_release' => $actor->isAdmin(),
            'requestToken' => (string) Str::uuid()];

        return $request->expectsJson() ? response()->json($payload)->header('Cache-Control', 'private, no-store')
            : Inertia::render('Finance/SettlementDetail', $payload);
    }

    public function command(Request $request, int $order, string $action)
    {
        try {
            $event = $this->settlements->command($request->user(), $order, $action, $request->except('proof'), $request->file('proof'));
        } catch (DomainException $exception) {
            return $request->expectsJson() ? response()->json(['message' => $exception->getMessage()], 409)
                : back()->withErrors(['settlement' => $exception->getMessage()]);
        }

        return $request->expectsJson() ? response()->json(['reference' => $event->reference])->header('Cache-Control', 'private, no-store')
            : back()->with('success', 'The settlement decision was recorded.');
    }

    public function proof(Request $request, int $order, int $event)
    {
        $record = $this->settlements->scoped($request->user())->whereKey($order)->firstOrFail()->sellerSettlement;
        $event = $record?->events()->whereKey($event)->first();
        abort_unless($event?->proof_path && Storage::disk('local')->exists($event->proof_path), 404);
        abort_unless(hash_equals($event->proof_hash, hash_file('sha256', Storage::disk('local')->path($event->proof_path))), 404);
        $response = Storage::disk('local')->response($event->proof_path, 'payment-evidence', ['Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff'], 'attachment');

        return $response;
    }
}
