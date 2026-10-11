<?php

namespace App\Http\Controllers;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait HandlesVoucherCampaignWrites
{
    private function campaignWrite(Request $request, callable $work): mixed
    {
        try {
            return $work();
        } catch (HttpResponseException|HttpException $exception) {
            $response = $exception instanceof HttpResponseException ? $exception->getResponse() : null;
            $status = $response?->getStatusCode() ?? $exception->getStatusCode();
            if (! $request->header('X-Inertia') || ! in_array($status, [409, 422], true)) {
                throw $exception;
            }
            $message = $response ? (json_decode($response->getContent(), true)['message'] ?? 'Reload this campaign before trying again.') : $exception->getMessage();

            return back()->withErrors(['campaign' => $message]);
        }
    }
}
