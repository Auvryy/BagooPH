<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait HandlesAdvertisementWrites
{
    private function write(Request $request, callable $work, string $message)
    {
        try {
            $ad = $work();

            return redirect($this->baseUrl($request).'/'.$ad->id)->with('success', $message);
        } catch (HttpException $exception) {
            if (! $request->header('X-Inertia') || ! in_array($exception->getStatusCode(), [409, 422], true)) {
                throw $exception;
            }

            return back()->withErrors(['advertisement' => $exception->getMessage()]);
        }
    }
}
