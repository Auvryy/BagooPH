<?php

namespace App\Http\Controllers;

use App\Services\Finance\FinancialOversightService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use UnexpectedValueException;

class FinancialOversightController extends Controller
{
    public function __construct(private readonly FinancialOversightService $oversight) {}

    public function index(Request $request)
    {
        $payload = $this->oversight->page($request->user(), $request->query(), $request->url());

        return $request->expectsJson() ? response()->json($payload, $payload['error'] ? 503 : 200)->header('Cache-Control', 'private, no-store')
            : Inertia::render('Finance/Overview', $payload)->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, int $order)
    {
        try {
            $payload = $this->oversight->detail($request->user(), $order);
        } catch (QueryException|UnexpectedValueException $exception) {
            report($exception);
            abort(503, 'Original financial evidence is unavailable. Try again after the source is restored.');
        }

        return $request->expectsJson() ? response()->json($payload)->header('Cache-Control', 'private, no-store')
            : Inertia::render('Finance/Evidence', $payload)->toResponse($request)->header('Cache-Control', 'private, no-store');
    }
}
