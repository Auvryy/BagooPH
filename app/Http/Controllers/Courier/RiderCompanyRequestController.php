<?php

namespace App\Http\Controllers\Courier;

use App\Http\Controllers\Controller;
use App\Services\Logistics\RiderCompanyOfferService;
use App\Services\Logistics\RiderCompanyRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RiderCompanyRequestController extends Controller
{
    public function store(Request $request, RiderCompanyRequestService $service): JsonResponse|RedirectResponse
    {
        return $this->command($request, fn () => $service->submit($request->user(), $request->except('_token', '_method')),
            'Request submitted. View its current status below.');
    }

    public function withdraw(Request $request, string $companyRequest, RiderCompanyRequestService $service): JsonResponse|RedirectResponse
    {
        return $this->command($request, fn () => $service->withdraw($request->user(), $companyRequest, $request->except('_token', '_method')),
            'Withdrawal recorded. View the request history below.');
    }

    public function accept(Request $request, string $companyRequest, RiderCompanyOfferService $service): JsonResponse|RedirectResponse
    {
        return $this->command($request, fn () => $service->riderDecision($request->user(), $companyRequest, 'accepted', $request->except('_token', '_method')),
            'Assignment accepted. Your saved placement is ready; go on duty when you are ready to work.');
    }

    public function decline(Request $request, string $companyRequest, RiderCompanyOfferService $service): JsonResponse|RedirectResponse
    {
        return $this->command($request, fn () => $service->riderDecision($request->user(), $companyRequest, 'declined', $request->except('_token', '_method')),
            'Offer declined. The decision is saved in your request history.');
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
        if ($request->expectsJson()) {
            return response()->json(['data' => $result])->header('Cache-Control', 'no-store, private');
        }
        $path = $request->is('courier/*') ? '/courier/profile' : '/profile';

        return redirect($path.'?tab=assignment')->with('success', $message);
    }
}
