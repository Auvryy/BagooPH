<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Inertia\Inertia;

trait EncryptsSensitiveHistory
{
    /**
     * Encrypt Inertia history on HTTPS and localhost origins while staying plain-HTTP compatible elsewhere.
     */
    protected function encryptSensitiveHistory(Request $request): void
    {
        $host = $request->getHost();

        Inertia::encryptHistory(
            $request->isSecure() || $host === 'localhost' || str_ends_with($host, '.localhost')
        );
    }
}
