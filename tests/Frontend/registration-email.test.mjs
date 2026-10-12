import { after, afterEach, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { buildSync } from 'esbuild';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-email-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = buildSync({
    stdin: { contents: [
        "export * from '@/utils/registrationEmailAvailability';",
        "export { AuthRequestError } from '@/utils/authRequests';",
        "export { default as Feedback } from '@/Components/RegistrationEmailFeedback';",
    ].join('\n'), resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', write: false,
});
const path = join(temporary, 'email.mjs');
writeFileSync(path, bundle.outputFiles[0].text);
const { RegistrationEmailAvailability, AuthRequestError, Feedback } = await import(pathToFileURL(path).href);
const controllers = [];
const originalFetch = globalThis.fetch;
afterEach(() => { controllers.splice(0).forEach(controller => controller.cancel()); globalThis.fetch = originalFetch; });
const tick = () => new Promise(resolve => setImmediate(resolve));
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

function setup(options = {}) {
    const states = [];
    const requests = [];
    const controller = new RegistrationEmailAvailability(state => states.push(state), {
        debounceMs: 5000, timeoutMs: 5000,
        check(email, signal) {
            return new Promise((resolve, reject) => requests.push({ email, signal, resolve, reject }));
        }, ...options,
    });
    controllers.push(controller);
    return { controller, states, requests, latest: () => states.at(-1) };
}

test('blank and incomplete or invalid addresses never request availability', async () => {
    const { controller, requests, latest } = setup();
    for (const value of ['', '  ', 'person', '@bagoo.test', 'person@bagoo', 'person @bagoo.test', 'person@@bagoo.test', 'person@bagoo.test\n', 'person\u200b@bagoo.test', 'a'.repeat(256) + '@bagoo.test']) {
        controller.update(value);
        await controller.checkNow();
        assert.equal(latest()?.status ?? 'idle', 'idle');
    }
    assert.equal(requests.length, 0);
});

test('typing pauses collapse rapid edits to one normalized request', async () => {
    const { controller, requests, latest } = setup({ debounceMs: 15 });
    controller.update('first@bagoo.test');
    controller.update('second@bagoo.test');
    controller.update('  Applicant@BAGOO.TEST  ');
    assert.equal(requests.length, 0);
    await sleep(40);
    assert.equal(requests.length, 1);
    assert.equal(requests[0].email, 'applicant@bagoo.test');
    assert.equal(latest().status, 'checking');
    requests[0].resolve({ email: 'applicant@bagoo.test', available: true });
    await tick();
    assert.equal(latest().status, 'available');
});

test('leaving the field checks immediately and does not duplicate an active or completed request', async () => {
    const { controller, requests, latest } = setup();
    controller.update('Applicant@bagoo.test');
    const pending = controller.checkNow();
    await controller.checkNow();
    assert.equal(requests.length, 1);
    requests[0].resolve({ email: 'applicant@bagoo.test', available: false });
    await pending;
    assert.equal(latest().status, 'taken');
    controller.update('  APPLICANT@BAGOO.TEST  ');
    await controller.checkNow();
    assert.equal(requests.length, 1);
});

test('out-of-order replies cannot apply availability to a newly edited email even if abort is ignored', async () => {
    const { controller, requests, latest } = setup();
    controller.update('old@bagoo.test');
    const old = controller.checkNow();
    controller.update('current@bagoo.test');
    const current = controller.checkNow();
    assert.equal(requests[0].signal.aborted, true);
    requests[1].resolve({ email: 'current@bagoo.test', available: false });
    await current;
    requests[0].resolve({ email: 'old@bagoo.test', available: true });
    await old;
    assert.deepEqual(latest(), { email: 'current@bagoo.test', status: 'taken', message: undefined });
});

test('clearing or disabling a field cancels requests and removes old feedback', async () => {
    for (const [email, enabled] of [['', true], ['person@bagoo.test', false]]) {
        const { controller, requests, latest } = setup();
        controller.update('person@bagoo.test');
        const pending = controller.checkNow();
        controller.update(email, enabled);
        requests[0].resolve({ email: 'person@bagoo.test', available: true });
        await pending;
        assert.equal(requests[0].signal.aborted, true);
        assert.equal(latest().status, 'idle');
    }
});

test('effect cleanup suppresses late replies and permits a fresh check when mounted again', async () => {
    const { controller, requests, states, latest } = setup();
    controller.update('person@bagoo.test');
    const pending = controller.checkNow();
    controller.cancel();
    const length = states.length;
    requests[0].resolve({ email: 'person@bagoo.test', available: true });
    await pending;
    assert.equal(states.length, length);
    controller.update('person@bagoo.test');
    const resumed = controller.checkNow();
    requests[1].resolve({ email: 'person@bagoo.test', available: true });
    await resumed;
    assert.equal(latest().status, 'available');
});

test('unconfirmed or mismatched replies never claim availability', async () => {
    for (const body of [{}, { available: true }, { email: 'other@bagoo.test', available: true }, { email: 'person@bagoo.test', available: 'true' }, { email: 'person@bagoo.test', available: 1 }]) {
        const { controller, requests, latest } = setup();
        controller.update('person@bagoo.test');
        const pending = controller.checkNow();
        requests[0].resolve(body);
        await pending;
        assert.equal(latest().status, 'error');
        assert.match(latest().message, /try again/i);
    }
});

test('server format rejection remains invalid until the email changes', async () => {
    const { controller, requests, latest } = setup();
    controller.update('person@bagoo.test');
    const pending = controller.checkNow();
    requests[0].reject(new AuthRequestError('Invalid email.', 422, { email: 'Enter a valid email address.' }));
    await pending;
    assert.equal(latest().status, 'invalid');
    assert.equal(latest().message, 'Enter a valid email address.');
    await controller.checkNow();
    assert.equal(requests.length, 1);
    controller.update('new@bagoo.test');
    assert.equal(latest().status, 'waiting');
});

test('failed checks offer retry and never cache a false success', async () => {
    for (const error of [new Error('Network failure'), new AuthRequestError('Reload the page.', 419), new AuthRequestError('Sign out before continuing.', 409), new AuthRequestError('Please try again.', 503)]) {
        const { controller, requests, latest } = setup();
        controller.update('person@bagoo.test');
        const pending = controller.checkNow();
        requests[0].reject(error);
        await pending;
        assert.equal(latest().status, 'error');
        const retry = controller.checkNow();
        requests[1].resolve({ email: 'person@bagoo.test', available: true });
        await retry;
        assert.equal(latest().status, 'available');
    }
});

test('a timed-out request cannot overwrite its retry result', async () => {
    const { controller, requests, latest } = setup({ timeoutMs: 15 });
    controller.update('person@bagoo.test');
    const pending = controller.checkNow();
    await sleep(40);
    assert.equal(requests[0].signal.aborted, true);
    assert.equal(latest().status, 'error');
    assert.match(latest().message, /too long/);
    const retry = controller.checkNow();
    requests[1].resolve({ email: 'person@bagoo.test', available: false });
    await retry;
    requests[0].resolve({ email: 'person@bagoo.test', available: true });
    await pending;
    assert.equal(latest().status, 'taken');
});

test('rate-limit cooldown applies to retries and to another edited email', async () => {
    let now = 1000;
    const { controller, requests, latest } = setup({ now: () => now });
    controller.update('person@bagoo.test');
    const pending = controller.checkNow();
    requests[0].reject(new AuthRequestError('Too many attempts.', 429, {}, 60));
    await pending;
    await controller.checkNow();
    controller.update('other@bagoo.test');
    await controller.checkNow();
    assert.equal(requests.length, 1);
    assert.match(latest().message, /60 seconds/);
    now += 60000;
    const retry = controller.checkNow();
    requests[1].resolve({ email: 'other@bagoo.test', available: true });
    await retry;
    assert.equal(latest().status, 'available');
});

test('the default request sends only normalized email to the web endpoint', async () => {
    let request;
    globalThis.fetch = async (path, options) => {
        request = { path, options };
        return new Response(JSON.stringify({ email: 'person@bagoo.test', available: true }));
    };
    const states = [];
    const controller = new RegistrationEmailAvailability(state => states.push(state), { debounceMs: 5000 });
    controllers.push(controller);
    controller.update('  Person@BAGOO.TEST  ');
    await controller.checkNow();
    assert.equal(request.path, '/register/email/validate');
    assert.equal(request.options.method, 'POST');
    assert.equal(request.options.headers.Accept, 'application/json');
    assert.deepEqual(JSON.parse(request.options.body), { email: 'person@bagoo.test' });
    assert.equal(states.at(-1).status, 'available');
});

test('feedback is announced, preserves field errors and requires verification after availability', () => {
    const html = (state, error) => renderToStaticMarkup(createElement(Feedback, { state: { email: 'person@bagoo.test', ...state }, error, onRetry() {} }));
    assert.match(html({ status: 'checking' }), /Checking email/);
    const available = html({ status: 'available' });
    assert.match(available, /Email is available\. Verify it next\./);
    assert.match(available, /aria-live="polite"/);
    assert.doesNotMatch(available, /email is verified|mailbox exists/i);
    assert.match(html({ status: 'taken' }), /already registered/);
    const rejected = html({ status: 'available' }, 'This address is already registered.');
    assert.match(rejected, /This address is already registered/);
    assert.doesNotMatch(rejected, /Email is available/);
    const retry = html({ status: 'error', message: 'Check your connection.' });
    assert.match(retry, /Check your connection/);
    assert.match(retry, /type="button"/);
    assert.match(retry, /Try again/);
    assert.doesNotMatch(html({ status: 'idle' }), /Try again|available|registered/);
});
