<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Services\Logistics\RiderCompanyRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RiderCompanyIntakeController extends Controller
{
    public function update(Request $request, RiderCompanyRequestService $service): JsonResponse|RedirectResponse
    {
        try {
            $result = $service->changeIntake($request->user(), $request->except('_token', '_method'));
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409 || $request->expectsJson()) {
                throw $exception;
            }

            return back()->withErrors(['intake' => $exception->getMessage()]);
        }

        return $request->expectsJson()
            ? response()->json(['data' => $result])->header('Cache-Control', 'no-store, private')
            : back()->with('success', 'Rider request settings saved.');
    }
}
