<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Services\Logistics\RiderCompanyOfferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RiderCompanyOfferController extends Controller
{
    public function store(Request $request, string $companyRequest, RiderCompanyOfferService $service): JsonResponse|RedirectResponse
    {
        return $this->command($request, fn () => $service->issue($request->user(), $companyRequest, $request->except('_token', '_method')),
            'Offer saved. The rider must read and accept it before placement.');
    }

    public function reject(Request $request, string $companyRequest, RiderCompanyOfferService $service): JsonResponse|RedirectResponse
    {
        return $this->command($request, fn () => $service->companyDecision($request->user(), $companyRequest, 'rejected', $request->except('_token', '_method')),
            'Request decision saved.');
    }

    public function cancel(Request $request, string $companyRequest, RiderCompanyOfferService $service): JsonResponse|RedirectResponse
    {
        return $this->command($request, fn () => $service->companyDecision($request->user(), $companyRequest, 'cancelled', $request->except('_token', '_method')),
            'Offer withdrawn. The original terms remain in history.');
    }

    private function command(Request $request, callable $operation, string $message): JsonResponse|RedirectResponse
    {
        try {
            $result = $operation();
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409 || $request->expectsJson()) {
                throw $exception;
            }

            return back()->withErrors(['company_request' => $exception->getMessage()]);
        }

        return $request->expectsJson()
            ? response()->json(['data' => $result])->header('Cache-Control', 'no-store, private')
            : back()->with('success', $message);
    }
}
