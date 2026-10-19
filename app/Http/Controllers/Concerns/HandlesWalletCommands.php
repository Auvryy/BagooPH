<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

trait HandlesWalletCommands
{
    private function walletCommand(Request $request, string $field, callable $command): mixed
    {
        try {
            return $command();
        } catch (HttpExceptionInterface $error) {
            if (! $request->expectsJson() && in_array($error->getStatusCode(), [409, 422], true)) {
                throw ValidationException::withMessages([$field => $error->getMessage()]);
            }

            throw $error;
        }
    }
}
