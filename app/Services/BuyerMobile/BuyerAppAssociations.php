<?php

namespace App\Services\BuyerMobile;

use Illuminate\Http\Request;

class BuyerAppAssociations
{
    public function android(Request $request): ?array
    {
        $id = config('buyer_mobile.links.android_application_id');
        $fingerprints = config('buyer_mobile.links.android_sha256_fingerprints');
        if (! $this->originMatches($request) || ! $this->applicationId($id)
            || ! is_array($fingerprints) || ! array_is_list($fingerprints) || $fingerprints === [] || count($fingerprints) > 8) {
            return null;
        }
        foreach ($fingerprints as $fingerprint) {
            if (! is_string($fingerprint) || preg_match('/\A(?:[A-Fa-f0-9]{2}:){31}[A-Fa-f0-9]{2}\z/', $fingerprint) !== 1) {
                return null;
            }
        }

        return [[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => [
                'namespace' => 'android_app', 'package_name' => $id,
                'sha256_cert_fingerprints' => array_values(array_unique(array_map('strtoupper', $fingerprints))),
            ],
            'relation_extensions' => ['delegate_permission/common.handle_all_urls' => [
                'dynamic_app_link_components' => $this->components(),
            ]],
        ]];
    }

    public function apple(Request $request): ?array
    {
        $team = config('buyer_mobile.links.apple_team_id');
        $bundle = config('buyer_mobile.links.ios_bundle_id');
        if (! $this->originMatches($request) || ! is_string($team) || preg_match('/\A[A-Z0-9]{10}\z/', $team) !== 1
            || ! $this->applicationId($bundle, apple: true)) {
            return null;
        }

        return ['applinks' => ['details' => [[
            'appIDs' => [$team.'.'.$bundle], 'components' => $this->components(),
        ]]]];
    }

    public function components(): array
    {
        // OS matching is deliberately narrower than a whole-portal prefix. Native
        // parsing and server authorization still validate IDs, queries and owners.
        $paths = [
            '/', '/track', '/track/*', '/cart', '/product/*', '/buyer/search', '/buyer/product/*',
            '/shop/*', '/promotions/*', '/advertisement/*', '/notifications', '/buyer-returns',
            '/buyer-returns/*', '/pending-approval', '/verify-email', '/verify-email/*', '/checkout',
            '/buyer/profile', '/buyer/orders', '/buyer/orders/*', '/buyer/messages', '/buyer/addresses',
            '/buyer/saved-products', '/buyer/followed-shops', '/account/status', '/account/settings',
            '/account/security', '/wallet/payments', '/wallet/payments/*',
        ];
        $components = array_map(static fn (string $path): array => ['/' => $path], $paths);

        return [...$components,
            ['/' => '/reset-password/*', '?' => ['role' => 'buyer']],
            ['/' => '/reset-password/*', '?' => ['role' => '*'], 'exclude' => true],
            ['/' => '/reset-password/*'],
            ['/' => '*', 'exclude' => true],
        ];
    }

    private function originMatches(Request $request): bool
    {
        $origin = config('buyer_mobile.links.origin');
        if (config('buyer_mobile.links.enabled') !== true || ! is_string($origin) || strlen($origin) > 255) {
            return false;
        }
        $parts = parse_url($origin);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || ($parts['path'] ?? '') !== ''
            || (isset($parts['port']) && $parts['port'] !== 443)
            || ! filter_var($parts['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || ! str_contains($parts['host'], '.') || filter_var($parts['host'], FILTER_VALIDATE_IP)
            || preg_match('/\A(?:seller|courier|hub|admin)\./i', $parts['host']) === 1) {
            return false;
        }

        return $request->isSecure() && $request->getPort() === 443 && strtolower($request->getHost()) === strtolower($parts['host']);
    }

    private function applicationId(mixed $id, bool $apple = false): bool
    {
        return is_string($id) && strlen($id) <= 255
            && preg_match($apple ? '/\A[A-Za-z][A-Za-z0-9-]*(?:\.[A-Za-z][A-Za-z0-9-]*){2,15}\z/' : '/\A[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*){2,15}\z/', $id) === 1;
    }
}
