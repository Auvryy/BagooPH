import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { buildSync } from 'esbuild';
import { route } from '../../vendor/tightenco/ziggy/dist/index.esm.js';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = buildSync({ entryPoints: [resolve('resources/js/utils/routeOrigin.ts')], bundle: true, platform: 'node',
    format: 'esm', write: false });
const modulePath = join(temporary, 'auth.mjs');
writeFileSync(modulePath, bundle.outputFiles[0].text);
const { configureRouteOrigin } = await import(pathToFileURL(modulePath).href);

for (const portal of ['', 'seller.', 'courier.', 'hub.', 'admin.']) {
    test(`authentication URLs keep HTTPS for the ${portal || 'buyer'} portal behind an HTTP upstream`, () => {
        const host = `${portal}bagoo.test`;
        const config = { url: `http://${host}`, port: null, defaults: {}, routes: {
            login: { uri: 'login', methods: ['GET', 'HEAD'] }, logout: { uri: 'logout', methods: ['POST'] },
        } };
        const origin = new URL(`https://${host}/login`);
        assert.equal(route('login', undefined, true, config), `http://${host}/login`);
        configureRouteOrigin(config, origin);
        assert.equal(route('login', undefined, true, config), `https://${host}/login`);
        assert.equal(route('logout', undefined, true, config), `https://${host}/logout`);
        assert.equal(config.port, null);
    });
}

test('local HTTP and a custom port remain usable while configured foreign route domains are retained', () => {
    const config = { url: 'https://bagoo.test', port: null, defaults: {}, routes: {
        login: { uri: 'login', methods: ['GET', 'HEAD'] },
        'seller.home': { uri: 'dashboard', domain: 'seller.localhost', methods: ['GET', 'HEAD'] },
    } };
    configureRouteOrigin(config, new URL('http://localhost:8000/courier/login'));
    assert.equal(route('login', undefined, true, config), 'http://localhost:8000/login');
    assert.equal(route('seller.home', undefined, true, config), 'http://seller.localhost:8000/dashboard');
    assert.equal(config.port, 8000);
});
