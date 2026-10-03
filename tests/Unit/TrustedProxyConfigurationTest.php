<?php

namespace Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TrustedProxyConfigurationTest extends TestCase
{
    public static function validAllowlists(): array
    {
        $address = long2ip(0xC0000201);
        $subnet = long2ip(0xC0000200).'/24';
        $ipv6 = inet_ntop(hex2bin('20010db8000000000000000000000001'));

        return [
            ['', []], [' , , ', []],
            [$address, [$address]], [$subnet, [$subnet]],
            [" $address, $subnet, $ipv6/64 ", [$address, $subnet, "$ipv6/64"]],
        ];
    }

    #[DataProvider('validAllowlists')]
    public function test_explicit_allowlists_and_empty_defaults_are_supported(string $value, array $expected): void
    {
        $this->assertSame($expected, $this->configuration($value)['proxies']);
    }

    public static function unsafeAllowlists(): array
    {
        $address = long2ip(0xC0000201);
        $ipv6 = inet_ntop(hex2bin('20010db8000000000000000000000001'));

        return array_map(fn ($value) => [$value], [
            '*', '**', 'REMOTE_ADDR', '0', 'invalid',
            "$address/0", "$address/33", "$address/-1", "$address/24/32",
            "$ipv6/0", "$ipv6/129", "$address,0", "$address,*",
        ]);
    }

    #[DataProvider('unsafeAllowlists')]
    public function test_unsafe_or_invalid_entries_are_rejected_instead_of_ignored(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->configuration($value);
    }

    private function configuration(string $value): array
    {
        $previousEnv = $_ENV['TRUSTED_PROXIES'] ?? null;
        $previousServer = $_SERVER['TRUSTED_PROXIES'] ?? null;
        $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = $value;

        try {
            return require dirname(__DIR__, 2).'/config/trustedproxy.php';
        } finally {
            if ($previousEnv === null) {
                unset($_ENV['TRUSTED_PROXIES']);
            } else {
                $_ENV['TRUSTED_PROXIES'] = $previousEnv;
            }
            if ($previousServer === null) {
                unset($_SERVER['TRUSTED_PROXIES']);
            } else {
                $_SERVER['TRUSTED_PROXIES'] = $previousServer;
            }
        }
    }
}
