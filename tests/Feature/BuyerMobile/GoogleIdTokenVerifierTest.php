<?php

namespace Tests\Feature\BuyerMobile;

use App\Services\BuyerMobile\GoogleIdTokenVerifier;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\WithNativeGoogleTokens;
use Tests\TestCase;

class GoogleIdTokenVerifierTest extends TestCase
{
    use WithNativeGoogleTokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureNativeGoogle();
    }

    public function test_real_rsa_signature_and_claims_are_verified_using_cached_trusted_keys(): void
    {
        $verifier = app(GoogleIdTokenVerifier::class);
        $identity = $verifier->verify($this->googleToken(['email' => 'Native-Buyer@bagoo.test']));
        $this->assertSame('native-buyer@bagoo.test', $identity['email']);
        $this->assertSame('synthetic-google-subject', $identity['sub']);
        $verifier->verify($this->googleToken(['azp' => 'ios-test.apps.googleusercontent.com']));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === GoogleIdTokenVerifier::KEYS_URL && $request->method() === 'GET' && $request->data() === []);
    }

    public static function invalidClaims(): array
    {
        return [
            'wrong issuer' => [['iss' => 'https://identity.example.invalid']], 'wrong audience' => [['aud' => 'other.apps.googleusercontent.com']],
            'unapproved native app' => [['azp' => 'other-native.apps.googleusercontent.com']],
            'object audience' => [['aud' => ['client' => 'web-test.apps.googleusercontent.com']]],
            'missing party with multiple audiences' => [['aud' => ['web-test.apps.googleusercontent.com', 'other.apps.googleusercontent.com'], 'azp' => null]],
            'unverified email' => [['email_verified' => false]], 'coerced verification' => [['email_verified' => 'true']],
            'invalid email' => [['email' => 'invalid']], 'missing subject' => [['sub' => null]], 'long subject' => [['sub' => str_repeat('x', 256)]],
            'missing expiry' => [['exp' => null]], 'string expiry' => [['exp' => '9999999999']],
            'missing issued time' => [['iat' => null]], 'future issued time' => [['iat' => PHP_INT_MAX]],
            'string issued time' => [['iat' => '1']], 'string not-before' => [['nbf' => '1']],
        ];
    }

    #[DataProvider('invalidClaims')]
    public function test_invalid_signed_identity_claims_do_not_authenticate(array $claims): void
    {
        $this->expectException(ValidationException::class);
        app(GoogleIdTokenVerifier::class)->verify($this->googleToken($claims));
    }

    public function test_expired_and_future_tokens_are_rejected(): void
    {
        foreach ([['exp' => time() - 1], ['nbf' => time() + 300]] as $claims) {
            try {
                app(GoogleIdTokenVerifier::class)->verify($this->googleToken($claims));
                $this->fail('An unusable token was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('id_token', $exception->errors());
            }
        }
    }

    public function test_changed_signature_and_algorithm_cannot_use_a_trusted_key_id(): void
    {
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($other, $otherKey);
        foreach ([$this->googleToken(key: $otherKey), JWT::encode(['sub' => 'fake'], str_repeat('synthetic-hmac-key', 3), 'HS256', 'test-key'),
            $this->googleToken(headers: ['jku' => 'https://keys.example.invalid']), 'malformed', str_repeat('x', 16385)] as $token) {
            try {
                app(GoogleIdTokenVerifier::class)->verify($token);
                $this->fail('An untrusted signature or header was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('id_token', $exception->errors());
            }
        }
        Http::assertSentCount(1);
    }

    public function test_single_audience_without_party_is_valid_and_multiple_audiences_require_an_approved_party(): void
    {
        $verifier = app(GoogleIdTokenVerifier::class);
        $this->assertSame('synthetic-google-subject', $verifier->verify($this->googleToken(['azp' => null]))['sub']);
        $this->assertSame('synthetic-google-subject', $verifier->verify($this->googleToken(['aud' => ['web-test.apps.googleusercontent.com', 'other.apps.googleusercontent.com']]))['sub']);
    }

    public function test_key_rotation_refreshes_once_and_unknown_key_spam_is_bounded(): void
    {
        Cache::put('buyer-mobile:google-jwks:v1', ['jwks' => ['keys' => [array_replace($this->googleTestJwks['keys'][0], ['kid' => 'old-key'])]], 'fetched_at' => time() - 120], 3600);
        app(GoogleIdTokenVerifier::class)->verify($this->googleToken());
        for ($i = 0; $i < 3; $i++) {
            try {
                app(GoogleIdTokenVerifier::class)->verify($this->googleToken(headers: ['kid' => 'missing-key-'.$i]));
                $this->fail('An unknown signing key was accepted.');
            } catch (ValidationException) {
            }
        }
        Http::assertSentCount(1);
    }

    public function test_provider_no_store_or_zero_freshness_does_not_leave_reusable_keys(): void
    {
        foreach (['public, max-age=0', 'no-store', 'no-cache, max-age=3600'] as $policy) {
            Cache::flush();
            $this->googleResponseHeaders = ['Cache-Control' => $policy];
            app(GoogleIdTokenVerifier::class)->verify($this->googleToken());
            $this->assertFalse(Cache::has('buyer-mobile:google-jwks:v1'));
        }
    }

    public static function badProviders(): array
    {
        return ['outage' => [503, ['unavailable' => true]], 'redirect' => [302, []], 'malformed keys' => [200, ['keys' => []]],
            'untrusted algorithm' => [200, ['keys' => [['kid' => 'test-key', 'kty' => 'RSA', 'alg' => 'HS256']]]]];
    }

    #[DataProvider('badProviders')]
    public function test_unavailable_or_invalid_key_provider_fails_closed_without_unsafe_details(int $status, array $body): void
    {
        $this->googleResponseBody = $body;
        $this->googleResponseStatus = $status;
        try {
            app(GoogleIdTokenVerifier::class)->verify($this->googleToken());
            $this->fail('An unusable provider response was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('test-key', $exception->getMessage());
        }
    }
}
