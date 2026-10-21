<?php

namespace Tests;

use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Keep authenticated HTTP fixtures on one cookie-backed session, as a real client does. */
    public function be(Authenticatable $user, $guard = null)
    {
        $driver = $this->app['auth']->guard($guard);
        if ($driver instanceof SessionGuard) {
            if ($driver->id() !== $user->getAuthIdentifier()) {
                $this->app['session']->driver()->invalidate();
            }
            $this->app['session']->driver()->put($driver->getName(), $user->getAuthIdentifier());
            $this->withCookie(config('session.cookie'), $this->app['session']->driver()->getId());
            $this->withCredentials();
        }

        return parent::be($user, $guard);
    }

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        // Direct call() fixtures and JSON helpers share the same explicit client cookie jar.
        if ($cookies === [] && $this->withCredentials) {
            $cookies = $this->prepareCookiesForRequest();
        }
        $this->app->forgetInstance('auth.driver');
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() !== config('session.cookie') && ! str_starts_with($cookie->getName(), 'remember_web_')) {
                continue;
            }
            if ($cookie->getValue() === '') {
                unset($this->defaultCookies[$cookie->getName()]);
            } else {
                $this->defaultCookies[$cookie->getName()] = $this->encryptCookies
                    ? $response->getCookie($cookie->getName())->getValue() : $cookie->getValue();
                $this->withCredentials();
            }
        }

        return $response;
    }

    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        // Enforce isolated SQLite in-memory database to prevent test runners from wiping main database
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);

        return $app;
    }
}
