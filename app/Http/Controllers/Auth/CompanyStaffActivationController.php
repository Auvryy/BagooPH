<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountSessions\AccountSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class CompanyStaffActivationController extends Controller
{
    public function show(Request $request)
    {
        abort_unless($request->user()->must_change_password, 409, 'Your temporary password was already replaced.');

        $assignment = DB::table('company_staff_provisions')->join('logistics_companies', 'logistics_companies.id', '=', 'company_staff_provisions.logistics_company_id')
            ->join('logistics_hubs', 'logistics_hubs.id', '=', 'company_staff_provisions.hub_id')->where('company_staff_provisions.user_id', $request->user()->id)
            ->first(['logistics_companies.name as company', 'logistics_hubs.name as hub']);

        return Inertia::render('Auth/ActivateStaff', ['email' => $request->user()->email, 'assignment' => $assignment,
            'expired' => $request->user()->temporary_password_expires_at?->isPast() ?? true]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['current_password' => 'required|string|max:128', 'assignment_confirmed' => 'required|accepted',
            'password' => ['required', 'confirmed', 'string', 'max:128', 'different:current_password', Password::min(12)->mixedCase()->numbers()->symbols()]]);
        DB::transaction(function () use ($request, $data) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->must_change_password && $user->provisioned_company_id, 409);
            if (! $user->temporary_password_expires_at || $user->temporary_password_expires_at->isPast()) {
                throw ValidationException::withMessages(['current_password' => 'This temporary password expired. Ask your logistics company owner to issue another one.']);
            }
            if (! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'Enter the temporary password your company gave you.']);
            }
            $user->forceFill(['password' => $data['password'], 'must_change_password' => false, 'temporary_password_expires_at' => null])->save();
            DB::table('company_staff_provisions')->where('user_id', $user->id)->whereNull('accepted_at')->update(['accepted_at' => now()]);
            app(AccountSessionService::class)->passwordChanged($user, $request);
        });
        $request->session()->regenerate();

        return redirect()->route('verification.notice')->with('success', 'Password replaced. Verify your registered email to activate work access.');
    }
}
