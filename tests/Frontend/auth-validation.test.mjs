import { after, afterEach, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { buildSync } from 'esbuild';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createInertiaApp } from '@inertiajs/react';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-auth-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = buildSync({
    stdin: { contents: [
        "export * from '@/utils/authRequests';",
        "export * from '@/Components/PhoneInput';",
        "export { default as Phone } from '@/Components/PhoneInput';",
        "export { default as Buyer } from '@/Pages/Auth/Register';",
        "export { default as Seller } from '@/Pages/Auth/SellerRegister';",
        "export { default as Courier } from '@/Pages/Auth/CourierRegister';",
        "export { default as Logistics } from '@/Pages/Auth/LogisticsRegister';",
    ].join('\n'), resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', write: false,
    loader: { '.css': 'empty' },
});
const path = join(temporary, 'auth.mjs');
writeFileSync(path, bundle.outputFiles[0].text);
const ui = await import(pathToFileURL(path).href);
const originalFetch = globalThis.fetch;
afterEach(() => { globalThis.fetch = originalFetch; });
globalThis.route = name => '/' + String(name).replaceAll('.', '/');
after(() => delete globalThis.route);

function reply(status, body, headers = {}) {
    return new Response(typeof body === 'string' ? body : JSON.stringify(body), { status, headers });
}

test('email feedback checks the complete address and supports custom and demo domains', () => {
    for (const value of ['@example.com', 'name@example', 'name@bagoo', 'name @bagoo.test', 'name@@bagoo.test', 'name@bagoo..test', 'name@-bagoo.test', 'name@bagoo.test extra', 'buyer@bagoo.test\n', '\tbuyer@bagoo.test']) {
        assert.equal(ui.validEmailFormat(value), false, value);
    }
    for (const value of ['buyer@bagoo.test', 'buyer+orders@bagoo.test', 'buyer@department.bagoo.test', ' buyer@delivery-team.test ', 'buyer@bücher.test']) {
        assert.equal(ui.validEmailFormat(value), true, value);
    }
});

test('approved mobile prefixes and separators keep all national digits', () => {
    for (const value of ['09171234567', '639171234567', '+63 917 123 4567', '(0917) 123-4567', '9171234567']) {
        assert.equal(ui.extractNationalDigits(value), '9171234567');
    }
    assert.equal(ui.formatPhNationalNumber('9171234567'), '917 123 4567');
});

test('invalid and overlong mobile input is preserved for rejection', () => {
    for (const value of ['0917ABC4567', '++639171234567', '+649171234567', '０９１７１２３４５６７', '09171234567 ext 1']) {
        assert.equal(ui.extractNationalDigits(value), value);
    }
    assert.equal(ui.extractNationalDigits('091712345678'), '91712345678');
    assert.equal(ui.extractNationalDigits('009171234567'), '09171234567');
    assert.equal(ui.formatPhNationalNumber('91712345678'), '91712345678');
    const html = renderToStaticMarkup(createElement(ui.Phone, { value: '0917ABC4567', onChange() {}, error: 'Enter a valid mobile number.' }));
    assert.match(html, /value="0917ABC4567"/);
    assert.match(html, /aria-invalid="true"/);
    assert.doesNotMatch(html, /maxlength=/i);
});

test('an HTML expired-session response offers recovery without reporting a mail failure', async () => {
    globalThis.fetch = async () => reply(419, '<html>Page expired</html>');
    await assert.rejects(ui.requestOtp('send', { email: 'buyer@bagoo.test' }), error => {
        assert.equal(error.status, 419);
        assert.match(error.message, /session expired.*reload/);
        assert.doesNotMatch(error.message, /html|email service|sent/i);
        return true;
    });
});

test('non-JSON and malformed successful responses cannot verify an account', async () => {
    for (const body of ['<html>Unavailable</html>', [], {}, { success: true }, { success: false }, { success: true, token: '' }, { success: true, token: 'wrong' }]) {
        globalThis.fetch = async () => reply(200, body);
        await assert.rejects(ui.requestOtp('verify', { email: 'buyer@bagoo.test', code: '123456' }));
    }
});

test('only explicit send success reports that a verification code was sent', async () => {
    for (const body of [{}, { success: false, message: 'Could not send.' }]) {
        globalThis.fetch = async () => reply(200, body);
        await assert.rejects(ui.requestOtp('send', { email: 'buyer@bagoo.test' }));
    }
    globalThis.fetch = async () => reply(200, { success: true, cooldown: 60, expires_in: 600 });
    const result = await ui.requestOtp('send', { email: 'buyer@bagoo.test' });
    assert.equal(result.success, true);
    assert.equal(result.cooldown, 60);
});

test('field rejection and resend cooldown stay actionable', async () => {
    globalThis.fetch = async () => reply(422, { errors: { email: ['Enter a valid email.'] } });
    await assert.rejects(ui.requestOtp('send', {}), error => error.fields.email === 'Enter a valid email.');
    globalThis.fetch = async () => reply(429, { success: false, message: 'Please wait.', cooldown: 42.2 });
    await assert.rejects(ui.requestOtp('send', {}), error => error.cooldown === 43);
    globalThis.fetch = async () => reply(429, { message: 'Too many attempts.' }, { 'Retry-After': '60' });
    await assert.rejects(ui.requestOtp('send', {}), error => error.cooldown === 60);
});

test('delivery and transport errors provide a retry without exposing infrastructure responses', async () => {
    globalThis.fetch = async () => reply(503, { success: false, message: 'Internal transport details' });
    await assert.rejects(ui.requestOtp('send', {}), error => /try again/.test(error.message) && !/Internal/.test(error.message));
    globalThis.fetch = async () => { throw new TypeError('Failed to fetch'); };
    await assert.rejects(ui.requestOtp('send', {}), error => /Check your connection/.test(error.message));
});

test('duplicate verification requests are blocked while the first is pending', () => {
    const scope = new ui.AuthRequestScope();
    const first = scope.begin();
    assert.ok(first);
    assert.equal(scope.begin(), null);
    scope.finish(first);
    assert.ok(scope.begin());
});

test('a late response after close or email change cannot deliver the old verification token', async () => {
    let resolveRequest;
    globalThis.fetch = () => new Promise(resolve => { resolveRequest = resolve; });
    const scope = new ui.AuthRequestScope();
    const old = scope.begin();
    let verified = 0;
    const pending = ui.requestOtp('verify', { email: 'old@bagoo.test', code: '123456' }, old.signal)
        .then(() => { if (scope.current(old)) verified++; });
    scope.cancel();
    const current = scope.begin();
    resolveRequest(reply(200, { success: true, token: 'a'.repeat(40) }));
    await pending;
    assert.equal(verified, 0);
    assert.equal(old.signal.aborted, true);
    assert.equal(scope.current(current), true);
    scope.finish(old);
    assert.equal(scope.current(current), true);
});

test('preflight encodes files and repeated fields without altering raw input', () => {
    const data = { role: 'logistics', email: ' Buyer@bagoo.test ', password: '  a passphrase  ', phone: '0917ABC4567', vehicle_types: ['motorcycle', 'l300_van'], business_permit: new File(['document'], 'permit.pdf', { type: 'application/pdf' }) };
    const form = ui.registrationFormData(data);
    assert.equal(form.get('email'), data.email);
    assert.equal(form.get('password'), data.password);
    assert.equal(form.get('phone'), data.phone);
    assert.equal(form.get('vehicle_types[1]'), 'l300_van');
    assert.equal(form.get('business_permit').name, 'permit.pdf');
    assert.equal(ui.registrationFormData(data, 1).has('business_permit'), false);
    assert.equal(ui.registrationFormData(data, 1).get('step'), '1');
});

test('derived and nested server errors return to their visible registration fields and steps', () => {
    assert.deepEqual(ui.registrationErrors('buyer', { name: 'Full name is too long.' }), { first_name: 'Full name is too long.' });
    assert.deepEqual(ui.registrationErrors('seller', { shop_phone: 'Invalid contact.' }), { phone: 'Invalid contact.' });
    assert.deepEqual(ui.registrationErrors('logistics', { 'vehicle_types.0': 'Invalid vehicle.' }), { vehicle_types: 'Invalid vehicle.' });
    assert.equal(ui.registrationErrorStep('buyer', { first_name: 'Invalid.' }), 1);
    assert.equal(ui.registrationErrorStep('seller', { password: 'Too short.' }), 3);
    assert.equal(ui.registrationErrorStep('courier', { license_number: 'Invalid.' }), 2);
    assert.equal(ui.registrationErrorStep('logistics', { company_email: 'Invalid.' }), 1);
    assert.equal(ui.registrationErrorStep('logistics', { 'vehicle_types.0': 'Invalid.' }), 2);
    assert.equal(ui.registrationErrorStep('logistics', { business_permit: 'Required.' }), 3);
});

test('all registration pages preserve the portal shell and controlled form feedback', async () => {
    for (const [name, Component] of [['Buyer', ui.Buyer], ['Seller', ui.Seller], ['Courier', ui.Courier], ['Logistics', ui.Logistics]]) {
        let view;
        const props = { auth: { user: null }, flash: {}, errors: {}, birthDateLimits: { today: '2026-10-12', minimum: '1900-01-01', maximum: '2026-10-11', adult_maximum: '2008-10-12' }, masterCategories: [{ id: 1, name: 'Pet Supplies' }] };
        const result = await createInertiaApp({
            page: { component: 'Auth/' + name, props, url: '/register', version: null },
            resolve: () => Component,
            setup: ({ App, props }) => { view = createElement(App, props); return view; },
            render: () => renderToStaticMarkup(view),
        });
        assert.match(result.body, /novalidate=""/);
        assert.match(result.body, /aria-busy="false"/);
        assert.match(result.body, /<fieldset class="contents">/);
        assert.match(result.body, /BagooPH/);
        assert.doesNotMatch(result.body, /Min 8 characters|font-mono/);
    }
});
