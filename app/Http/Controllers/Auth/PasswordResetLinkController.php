<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SecretMailService;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        // We will send the password reset link to this user. Once we have attempted
        // to send the link, we will examine the response then see the message we
        // need to show to the user. Finally, we'll send out a proper response.
        try {
            app(SecretMailService::class)->assertSafeTransport();
            $status = Password::sendResetLink($request->only('email'), function (User $user, #[\SensitiveParameter] $token) {
                try {
                    $user->sendPasswordResetNotification($token);
                } catch (\Throwable $exception) {
                    Password::broker()->getRepository()->delete($user);
                    throw $exception;
                }
                event(new PasswordResetLinkSent($user));
            });
        } catch (\Throwable $exception) {
            Log::warning('Failed to dispatch a password reset link.', ['exception' => $exception::class]);

            return back()->withErrors(['email' => 'We could not send the password reset email. Please try again shortly.']);
        }

        if ($status == Password::RESET_LINK_SENT) {
            return back()->with('status', __($status));
        }

        throw ValidationException::withMessages([
            'email' => [trans($status)],
        ]);
    }
}
