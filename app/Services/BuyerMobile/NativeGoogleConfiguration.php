<?php

namespace App\Services\BuyerMobile;

use Illuminate\Support\Facades\Route;

class NativeGoogleConfiguration
{
    public function audiences(): array
    {
        return $this->clients('audiences');
    }

    public function authorizedParties(): array
    {
        return $this->clients('authorized_parties');
    }

    public function ready(): bool
    {
        return config('buyer_mobile.google.enabled') === true
            && $this->audiences() !== [] && $this->authorizedParties() !== []
            && Route::has('auth.google.native') && Route::has('auth.google.native.registration.store');
    }

    private function clients(string $key): array
    {
        $clients = config('buyer_mobile.google.'.$key, []);
        if (! is_array($clients) || ! array_is_list($clients) || count($clients) > 16) {
            return [];
        }
        foreach ($clients as $client) {
            if (! is_string($client) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,199}\.apps\.googleusercontent\.com\z/', $client) !== 1) {
                return [];
            }
        }

        return array_values(array_unique($clients));
    }
}
