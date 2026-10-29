<?php

namespace Tests\Concerns;

use App\Services\BuyerMobile\GoogleIdTokenVerifier;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

trait WithNativeGoogleTokens
{
    protected string $googleTestKey = '';

    protected array $googleTestJwks;

    protected int $googleResponseStatus = 200;

    protected ?array $googleResponseBody = null;

    protected array $googleResponseHeaders = ['Cache-Control' => 'public, max-age=3600'];

    protected function configureNativeGoogle(): void
    {
        config([
            'buyer_mobile.google.enabled' => true,
            'buyer_mobile.google.audiences' => ['web-test.apps.googleusercontent.com'],
            'buyer_mobile.google.authorized_parties' => ['android-test.apps.googleusercontent.com', 'ios-test.apps.googleusercontent.com'],
            'cache.default' => 'array',
        ]);
        Cache::flush();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $this->googleTestKey);
        $rsa = openssl_pkey_get_details($key)['rsa'];
        $this->googleTestJwks = ['keys' => [[
            'kty' => 'RSA', 'kid' => 'test-key', 'alg' => 'RS256', 'use' => 'sig',
            'n' => JWT::urlsafeB64Encode($rsa['n']), 'e' => JWT::urlsafeB64Encode($rsa['e']),
        ]]];
        Http::preventStrayRequests();
        Http::fake([GoogleIdTokenVerifier::KEYS_URL => fn () => Http::response($this->googleResponseBody ?? $this->googleTestJwks, $this->googleResponseStatus, $this->googleResponseHeaders)]);
    }

    protected function googleToken(array $claims = [], array $headers = [], ?string $key = null): string
    {
        $kid = $headers['kid'] ?? 'test-key';
        unset($headers['kid']);

        return JWT::encode(array_replace([
            'iss' => 'https://accounts.google.com', 'aud' => 'web-test.apps.googleusercontent.com',
            'azp' => 'android-test.apps.googleusercontent.com', 'sub' => 'synthetic-google-subject',
            'iat' => time() - 30, 'exp' => time() + 3600,
            'email' => 'native-buyer@bagoo.test', 'email_verified' => true, 'name' => 'Synthetic Buyer',
        ], $claims), $key ?? $this->googleTestKey, 'RS256', $kid, $headers);
    }
}
