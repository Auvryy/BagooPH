<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AccountSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        app(AccountSettingsService::class)->changePassword($request);

        return back();
    }

    public function sendGoogleCode(Request $request): JsonResponse
    {
        $result = app(AccountSettingsService::class)->sendGooglePasswordCode($request);
        if (! $result['success']) {
            $status = $result['status'] ?? 429;
            unset($result['status']);

            return response()->json($result, $status)->header('Cache-Control', 'no-store, private');
        }

        return response()->json($result)->header('Cache-Control', 'no-store, private');
    }

    public function setGooglePassword(Request $request): JsonResponse|RedirectResponse
    {
        app(AccountSettingsService::class)->setGooglePassword($request);

        return $request->expectsJson()
            ? response()->json(['success' => true, 'message' => 'Password set. You can now sign in with your email and password.'])->header('Cache-Control', 'no-store, private')
            : back()->with('success', 'Password set. You can now sign in with your email and password.');
    }
}
