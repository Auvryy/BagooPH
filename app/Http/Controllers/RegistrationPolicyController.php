<?php

namespace App\Http\Controllers;

use App\Services\RegistrationPolicyService;
use Inertia\Inertia;
use Inertia\Response;

class RegistrationPolicyController extends Controller
{
    public function terms(RegistrationPolicyService $policies, ?string $version = null): Response
    {
        abort_unless($version === null || $version === $policies::TERMS_VERSION, 404);

        return Inertia::render('Auth/Policy', ['document' => $policies->document('terms'), 'policies' => $policies->contract()]);
    }

    public function privacy(RegistrationPolicyService $policies, ?string $version = null): Response
    {
        abort_unless($version === null || $version === $policies::PRIVACY_VERSION, 404);

        return Inertia::render('Auth/Policy', ['document' => $policies->document('privacy'), 'policies' => $policies->contract()]);
    }
}
