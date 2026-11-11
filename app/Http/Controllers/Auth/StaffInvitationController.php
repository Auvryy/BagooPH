<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Logistics\StaffInvitationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class StaffInvitationController extends Controller
{
    public function show()
    {
        return Inertia::render('Auth/AcceptWorkInvitation');
    }

    private function identity(Request $request): array
    {
        return $request->validate(['email' => 'required|string|email:rfc|max:255', 'kind' => 'required|in:operator,rider']);
    }

    public function send(Request $request, StaffInvitationService $invitations)
    {
        $data = $this->identity($request);
        $user = $invitations->pending($data['email'], $data['kind']);
        if ($user) {
            $result = $invitations->send($user);
            if (! $result['success']) {
                return response()->json($result, $result['status'] ?? 429);
            }
        }

        return response()->json(['success' => true, 'message' => 'If an active work invitation matches these details, a setup code has been emailed. Codes expire in 10 minutes.', 'cooldown' => 60]);
    }

    public function verify(Request $request, StaffInvitationService $invitations)
    {
        $data = $this->identity($request);
        $code = $request->validate(['code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/']]);
        $result = $invitations->verify($data['email'], $data['kind'], $code['code']);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function activate(Request $request, StaffInvitationService $invitations)
    {
        $data = $this->identity($request);
        $setup = $request->validate(['token' => ['required', 'string', 'size:40'], 'assignment_confirmed' => 'required|accepted', 'assignment_token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'confirmed', 'max:128', 'not_regex:/[\\p{C}]/u', Password::min(12)->mixedCase()->numbers()->symbols()]]);
        $activated = $invitations->activate($data['email'], $data['kind'], $setup['token'], $setup['password'], $setup['assignment_token']);
        if ($request->hasSession() && $request->user()?->id === $activated->id) {
            Auth::guard('web')->logoutCurrentDevice();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['success' => true, 'message' => 'Work account activated. Sign in with your own password.']);
    }
}
