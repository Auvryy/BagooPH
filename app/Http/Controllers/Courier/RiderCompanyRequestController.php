<?php

namespace App\Http\Controllers\Courier;

use App\Http\Controllers\Controller;
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
