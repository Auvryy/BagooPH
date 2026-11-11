<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountSessions\AccountSessionService;
use App\Services\Logistics\LogisticsAccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class LogisticsAuthController extends Controller
{
    public function login(Request $request, LogisticsAccountService $accounts)
    {
        $data = $request->validate(['email' => 'required|string|email:rfc|max:255', 'password' => 'required|string|max:128',
            'device_name' => 'required|string|max:80', 'hub_id' => 'prohibited', 'role' => 'prohibited', 'company_id' => 'prohibited']);
        $user = User::where('role', 'logistics')->whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim($data['email']))])->first();
        if (! $user || ! $user->password_login_enabled || ! Hash::check($data['password'], $user->getAuthPassword())) {
            return response()->json(['message' => 'Email or password is incorrect for your logistics operator account.'], 422);
        }
        if ($user->must_change_password || $user->needsCompanyEmailVerification()) {
            return response()->json(['message' => 'Complete your work account setup before signing in.',
                'activation_required' => true, 'activation_url' => '/work-account/setup'], 423);
        }

        return response()->json(['data' => $accounts->session($user, $data['device_name'], $request)]);
    }

    public function me(Request $request, LogisticsAccountService $accounts)
    {
        return response()->json(['data' => $accounts->resource($request->user())]);
    }

    public function logout(Request $request)
    {
        app(AccountSessionService::class)->logoutNative($request->user(), $request->user()->currentAccessToken());

        return response()->json(['message' => 'Signed out.']);
    }
}
