<?php

namespace Tests\Feature\E2E\Support;

use Illuminate\Testing\TestResponse;

trait InteractsWithPortals
{
    /**
     * Map of logical portal aliases to official subdomains.
     */
    protected array $portalDomains = [
        'buyer' => 'bagooph.shop',
        'seller' => 'seller.bagooph.shop',
        'courier' => 'courier.bagooph.shop',
        'hub' => 'hub.bagooph.shop',
        'admin' => 'admin.bagooph.shop',
    ];

    /**
     * Set the current request host for subsequent requests.
     */
    public function onPortal(string $portal = 'buyer'): static
    {
        $host = $this->portalDomains[$portal] ?? $portal;
        return $this->withServerVariables(['HTTP_HOST' => $host]);
    }

    /**
     * Resolve absolute portal URL for a given relative URI.
     */
    public function portalUrl(string $portal, string $uri): string
    {
        $host = $this->portalDomains[$portal] ?? $portal;
        if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
            return $uri;
        }
        $path = str_starts_with($uri, '/') ? $uri : '/' . $uri;
        return "http://{$host}{$path}";
    }

    /**
     * Perform a GET request targeting a specific portal.
     */
    public function portalGet(string $portal, string $uri, array $headers = []): TestResponse
    {
        return $this->get($this->portalUrl($portal, $uri), $headers);
    }

    /**
     * Perform a POST request targeting a specific portal.
     */
    public function portalPost(string $portal, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->post($this->portalUrl($portal, $uri), $data, $headers);
    }

    /**
     * Perform a PATCH request targeting a specific portal.
     */
    public function portalPatch(string $portal, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->patch($this->portalUrl($portal, $uri), $data, $headers);
    }

    /**
     * Perform a PUT request targeting a specific portal.
     */
    public function portalPut(string $portal, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->put($this->portalUrl($portal, $uri), $data, $headers);
    }

    /**
     * Perform a DELETE request targeting a specific portal.
     */
    public function portalDelete(string $portal, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->delete($this->portalUrl($portal, $uri), $data, $headers);
    }
}
