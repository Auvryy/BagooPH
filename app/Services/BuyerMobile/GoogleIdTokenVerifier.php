<?php

namespace App\Services\BuyerMobile;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class GoogleIdTokenVerifier
{
    public const KEYS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    private const CACHE_KEY = 'buyer-mobile:google-jwks:v1';

    public function __construct(private NativeGoogleConfiguration $configuration) {}

    public function verify(#[\SensitiveParameter] string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || strlen($token) > 16384) {
            $this->invalid();
        }
        try {
            $header = json_decode(JWT::urlsafeB64Decode($parts[0]), true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $this->invalid();
        }
        if (! is_array($header) || ($header['alg'] ?? null) !== 'RS256'
            || ! is_string($header['kid'] ?? null) || preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $header['kid']) !== 1
            || isset($header['crit']) || isset($header['jku']) || isset($header['x5u'])) {
            $this->invalid();
        }

        $keys = $this->keys($header['kid']);
        if (! isset($keys[$header['kid']])) {
            $this->invalid();
        }
        try {
            $claims = (array) JWT::decode($token, $keys);
        } catch (Throwable) {
            $this->invalid();
        }
        $now = time();
        $audience = $claims['aud'] ?? null;
        $audiences = is_string($audience) ? [$audience] : $audience;
        $party = $claims['azp'] ?? null;
        if (! is_array($audiences) || ! array_is_list($audiences) || $audiences === [] || count($audiences) > 16
            || count(array_filter($audiences, 'is_string')) !== count($audiences)
            || array_intersect($audiences, $this->configuration->audiences()) === []
            || ($party !== null && (! is_string($party) || ! in_array($party, $this->configuration->authorizedParties(), true)))
            || (count($audiences) > 1 && $party === null)
            || ! in_array($claims['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)
            || ! is_int($claims['exp'] ?? null) || $claims['exp'] <= $now
            || ! is_int($claims['iat'] ?? null) || $claims['iat'] > $now || $claims['iat'] >= $claims['exp']
            || (isset($claims['nbf']) && (! is_int($claims['nbf']) || $claims['nbf'] > $now))
            || ! is_string($claims['sub'] ?? null) || preg_match('/\A[A-Za-z0-9_-]{1,255}\z/', $claims['sub']) !== 1
            || ($claims['email_verified'] ?? null) !== true
            || ! is_string($claims['email'] ?? null) || strlen($claims['email']) > 254
            || ! filter_var($claims['email'], FILTER_VALIDATE_EMAIL)) {
            $this->invalid();
        }

        $name = $claims['name'] ?? 'Buyer';
        $picture = $claims['picture'] ?? null;

        return [
            'sub' => $claims['sub'], 'email' => strtolower(trim($claims['email'])),
            'name' => is_string($name) && mb_strlen($name) <= 255 ? $name : 'Buyer',
            'avatar' => is_string($picture) && strlen($picture) <= 2048
                && filter_var($picture, FILTER_VALIDATE_URL) && parse_url($picture, PHP_URL_SCHEME) === 'https' ? $picture : null,
            'expires_at' => $claims['exp'],
        ];
    }

    private function keys(string $kid): array
    {
        try {
            $cached = Cache::get(self::CACHE_KEY);
            $jwks = is_array($cached) ? ($cached['jwks'] ?? null) : null;
            $hasKey = is_array($jwks) && in_array($kid, array_column($jwks['keys'] ?? [], 'kid'), true);
            if (! $hasKey && (! is_array($cached) || ($cached['fetched_at'] ?? 0) <= time() - 60)) {
                // Bound unknown-key refreshes across requests, including provider outages.
                if (! Cache::add(self::CACHE_KEY.':refresh', true, 60)) {
                    if ($jwks === null) {
                        throw new HttpException(503);
                    }
                } else {
                    $response = Http::acceptJson()->withOptions(['verify' => true])->connectTimeout(3)->timeout(5)->withoutRedirecting()->get(self::KEYS_URL);
                    if (! $response->successful() || strlen($response->body()) > 65536) {
                        throw new HttpException(503);
                    }
                    $jwks = $response->json();
                    $this->parseKeys($jwks);
                    $policy = $response->header('Cache-Control');
                    preg_match('/(?:^|,)\s*max-age=(\d+)/i', $policy, $age);
                    $ttl = preg_match('/(?:^|,)\s*(?:no-store|no-cache)(?:\s*,|$)/i', $policy) === 1
                        ? 0 : min(21600, max(0, (int) ($age[1] ?? 300)));
                    if ($ttl > 0) {
                        Cache::put(self::CACHE_KEY, ['jwks' => $jwks, 'fetched_at' => time()], $ttl);
                    } else {
                        Cache::forget(self::CACHE_KEY);
                    }
                }
            }

            return $this->parseKeys($jwks);
        } catch (Throwable) {
            // Do not attach provider bodies, claims or token-bearing exception chains.
            throw new HttpException(503, 'Google sign-in is temporarily unavailable. Use email sign-in or try again later.');
        }
    }

    private function parseKeys(mixed $jwks): array
    {
        if (! is_array($jwks) || ! is_array($jwks['keys'] ?? null) || ! array_is_list($jwks['keys'])
            || count($jwks['keys']) < 1 || count($jwks['keys']) > 16) {
            throw new HttpException(503);
        }
        $kids = [];
        foreach ($jwks['keys'] as $key) {
            if (! is_array($key) || ($key['kty'] ?? null) !== 'RSA' || ($key['alg'] ?? 'RS256') !== 'RS256'
                || ($key['use'] ?? 'sig') !== 'sig' || ! is_string($key['kid'] ?? null)
                || preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $key['kid']) !== 1 || in_array($key['kid'], $kids, true)) {
                throw new HttpException(503);
            }
            $kids[] = $key['kid'];
        }

        return JWK::parseKeySet($jwks, 'RS256');
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['id_token' => 'Google could not verify this sign-in. Start Google sign-in again.']);
    }
}
