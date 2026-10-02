<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     */
    public function store(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        try {
            $request->user()->sendEmailVerificationNotification();
        } catch (Throwable $exception) {
            Log::warning('Failed to dispatch an email verification link.', [
                'user_id' => $request->user()->id,
                'exception' => $exception::class,
            ]);

            return back()->withErrors([
                'email' => 'We could not send the verification email. Check the address and try again.',
            ]);
        }

        return back()->with('status', 'verification-link-sent');
    }
}
