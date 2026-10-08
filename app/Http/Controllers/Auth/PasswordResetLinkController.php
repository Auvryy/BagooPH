<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\AccountRecoveryNotification;
use App\Services\AccountEmailService;
use App\Services\SecretMailService;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            $email = strtolower(trim($request->input('email')));
            $owner = app(AccountEmailService::class)->recoveryOwner($email);
            $status = $owner ? Password::sendResetLink(['email' => $owner->email], function (User $user, #[\SensitiveParameter] $token) use ($email) {
                try {
                    DB::transaction(function () use ($user, $email, $token) {
                        $current = app(AccountEmailService::class)->recoveryOwner($email, lock: true);
                        if (! $current || $current->id !== $user->id) {
                            throw new \RuntimeException('The recovery address is no longer available.');
                        }
                        if (strcasecmp($email, $current->email) === 0) {
                            $current->sendPasswordResetNotification($token);
                        } else {
                            $current->notify(new AccountRecoveryNotification($token, $email));
                        }
                    });
                } catch (\Throwable $exception) {
                    Password::broker()->getRepository()->delete($user);
                    throw $exception;
                }
                event(new PasswordResetLinkSent($user));
            }) : Password::INVALID_USER;
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
