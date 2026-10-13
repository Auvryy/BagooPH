import { after, afterEach, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { buildSync } from 'esbuild';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-session-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = buildSync({
    stdin: { contents: [
        "export * from '@/utils/authSession';",
        "export * from '@/utils/authRequests';",
        "export * from '@/hooks/useGuestSessionGuard';",
        "export * from '@/utils/registrationEmailAvailability';",
    ].join('\n'), resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', write: false,
});
const path = join(temporary, 'session.mjs');
writeFileSync(path, bundle.outputFiles[0].text);
const ui = await import(pathToFileURL(path).href);
const original = { window: globalThis.window, document: globalThis.document, fetch: globalThis.fetch };
const cleanup = [];
afterEach(() => {
    cleanup.splice(0).forEach(stop => stop());
    for (const key of ['window', 'document', 'fetch']) {
        if (original[key] === undefined) delete globalThis[key]; else globalThis[key] = original[key];
    }
});
const tick = () => new Promise(resolve => setImmediate(resolve));
const authenticated = redirect => ({ code: 'ALREADY_AUTHENTICATED', authenticated: true, redirect });

test('only explicit session replies can route to the same origin or known portal siblings', () => {
    const origin = 'http://courier.localhost:8000';
    for (const target of ['/deliveries', '/pending-approval', 'http://localhost:8000/buyer', 'http://seller.localhost:8000/dashboard', 'http://hub.localhost:8000/dashboard', 'http://admin.localhost:8000/dashboard']) {
        assert.equal(ui.authenticatedSessionTarget(authenticated(target), 409, origin), target);
        assert.equal(ui.authenticatedSessionTarget(authenticated(target), 200, origin), target);
    }
    assert.equal(ui.authenticatedSessionTarget(authenticated('/courier/deliveries'), 409, 'http://127.0.0.1:8000'), '/courier/deliveries');
    for (const [body, status] of [[{ authenticated: false }, 200], [{ redirect: '/deliveries' }, 409], [authenticated('/deliveries'), 422], [authenticated('/deliveries'), 503], [[], 409], [null, 409]]) {
        assert.equal(ui.authenticatedSessionTarget(body, status, origin), undefined);
    }
});

test('malformed, unrelated, credential-bearing and downgraded session destinations are rejected', () => {
    for (const path of ['//untrusted.test', '/\\untrusted.test', 'javascript:alert(1)', 'https://untrusted.test', 'https://courier.localhost.evil.test', 'https://other.localhost', 'https://courier.localhost:8000/deliveries', 'http://courier.localhost/deliveries', 'http://user:password@courier.localhost:8000', '/deliveries\n', '', 'deliveries', 12]) {
        assert.equal(ui.authenticatedSessionTarget(authenticated(path), 409, 'http://courier.localhost:8000'), undefined, String(path));
    }
    assert.equal(ui.authenticatedSessionTarget(authenticated('http://courier.localhost/deliveries'), 409, 'https://courier.localhost'), undefined);
});

function browser(origin = 'http://courier.localhost:8000') {
    const targets = [];
    const events = new EventTarget();
    const visibility = Object.assign(new EventTarget(), { visibilityState: 'visible', querySelector: () => ({ content: 'test-csrf' }) });
    events.location = { origin, assign: target => targets.push(target) };
    globalThis.window = events;
    globalThis.document = visibility;
    return { events, visibility, targets };
}

test('a stale registration email request redirects the current account instead of offering sign out or retry', async () => {
    const { targets } = browser();
    const states = [];
    let request;
    globalThis.fetch = async (path, options) => {
        request = { path, options };
        return new Response(JSON.stringify(authenticated('/deliveries')), { status: 409 });
    };
    const controller = new ui.RegistrationEmailAvailability(state => states.push(state), { debounceMs: 5000 });
    cleanup.push(() => controller.cancel());
    controller.update('random-address@bagoo.test');
    await controller.checkNow();
    assert.deepEqual(targets, ['/deliveries']);
    assert.equal(states.at(-1).status, 'redirecting');
    assert.equal(states.at(-1).message, 'Returning to your account.');
    assert.deepEqual(JSON.parse(request.options.body), { email: 'random-address@bagoo.test' });
    await controller.checkNow();
    assert.deepEqual(targets, ['/deliveries']);
});

test('stale preflight and OTP requests use the server account destination without claiming verification', async () => {
    const { targets } = browser('https://seller.localhost');
    const destination = 'https://courier.localhost/deliveries';
    globalThis.fetch = async () => new Response(JSON.stringify(authenticated(destination)), { status: 409 });
    for (const path of ['/register/validate', '/api/otp/send', '/api/otp/verify']) {
        await assert.rejects(ui.authRequest(path, { email: 'random@bagoo.test' }), error => error.redirect === destination && error.status === 409 && !/sign out/i.test(error.message));
    }
    assert.deepEqual(targets, [destination, destination, destination]);
});

test('a guest availability response keeps the signup form and reports the actual availability', async () => {
    const { targets } = browser();
    globalThis.fetch = async () => new Response(JSON.stringify({ email: 'new@bagoo.test', available: true }));
    const reply = await ui.authRequest('/register/email/validate', { email: 'new@bagoo.test' });
    assert.equal(reply.available, true);
    assert.deepEqual(targets, []);
});

test('returning to an existing auth tab checks the session on focus, visibility and history restoration', async () => {
    const { events, visibility, targets } = browser();
    const requests = [];
    let signedIn = false;
    globalThis.fetch = async (path, options) => {
        requests.push({ path, options });
        return new Response(JSON.stringify(signedIn ? authenticated('/deliveries') : { authenticated: false }));
    };
    cleanup.push(ui.watchGuestSession());
    await tick();
    assert.equal(requests.length, 1);
    assert.deepEqual(targets, []);
    signedIn = true;
    for (const [source, name] of [[events, 'focus'], [visibility, 'visibilitychange'], [events, 'pageshow']]) {
        source.dispatchEvent(new Event(name));
        await tick();
    }
    assert.deepEqual(targets, ['/deliveries', '/deliveries', '/deliveries']);
    assert.ok(requests.every(request => request.path === '/auth/session' && request.options.cache === 'no-store' && request.options.credentials === 'same-origin'));
});

test('session checks deduplicate active requests and ignore disposed replies', async () => {
    const { events, visibility, targets } = browser();
    const pending = [];
    const stop = ui.watchGuestSession({ events, visibility, request: signal => new Promise(resolve => pending.push({ signal, resolve })) });
    cleanup.push(stop);
    events.dispatchEvent(new Event('focus'));
    events.dispatchEvent(new Event('pageshow'));
    assert.equal(pending.length, 1);
    stop();
    assert.equal(pending[0].signal.aborted, true);
    pending[0].resolve({ data: authenticated('/deliveries'), status: 200 });
    await tick();
    events.dispatchEvent(new Event('focus'));
    assert.equal(pending.length, 1);
    assert.deepEqual(targets, []);
});

test('session-read failures and hidden tabs preserve input and allow a later event retry', async () => {
    const { events, visibility, targets } = browser();
    let calls = 0;
    globalThis.fetch = async () => {
        calls++;
        if (calls === 1) throw new Error('Disconnected');
        return new Response(JSON.stringify(authenticated('/deliveries')));
    };
    cleanup.push(ui.watchGuestSession());
    await tick();
    assert.deepEqual(targets, []);
    visibility.visibilityState = 'hidden';
    events.dispatchEvent(new Event('focus'));
    await tick();
    assert.equal(calls, 1);
    visibility.visibilityState = 'visible';
    visibility.dispatchEvent(new Event('visibilitychange'));
    await tick();
    assert.deepEqual(targets, ['/deliveries']);
});

test('a timed-out session probe permits another focus check and ignores its late reply', async () => {
    const { events, visibility, targets } = browser();
    const requests = [];
    cleanup.push(ui.watchGuestSession({ events, visibility, timeoutMs: 15, request: signal => new Promise(resolve => requests.push({ signal, resolve })) }));
    await new Promise(resolve => setTimeout(resolve, 40));
    assert.equal(requests[0].signal.aborted, true);
    events.dispatchEvent(new Event('focus'));
    requests[1].resolve({ data: authenticated('/deliveries'), status: 200 });
    await tick();
    requests[0].resolve({ data: authenticated('/pending-approval'), status: 200 });
    await tick();
    assert.deepEqual(targets, ['/deliveries']);
});
