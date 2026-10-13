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
        "export * from '@/utils/registrationValidation';",
        "export * from '@/utils/passwordPolicy';",
        "export * from '@/utils/registrationFields';",
        "export * from '@/Components/RegistrationDemographics';",
        "export { default as AddressSelector } from '@/Components/PhilippineAddressSelector';",
        "export { default as PolicyAcknowledgement } from '@/Components/RegistrationPolicyAcknowledgement';",
        "export { default as ApplicationFields } from '@/Components/ApplicationFields';",
        "export { default as PersonalNames } from '@/Components/PersonalNameFields';",
        "export * from '@/Components/PhoneInput';",
        "export { default as Phone } from '@/Components/PhoneInput';",
        "export { default as Buyer } from '@/Pages/Auth/Register';",
        "export { default as Seller } from '@/Pages/Auth/SellerRegister';",
        "export { default as Courier } from '@/Pages/Auth/CourierRegister';",
        "export { default as Logistics } from '@/Pages/Auth/LogisticsRegister';",
        "export { default as Google } from '@/Pages/Auth/GoogleRegistration';",
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

test('new registration email edits trim outer spaces and lowercase without repairing malformed addresses', () => {
    assert.equal(ui.normalizeRegistrationEmail('  New.Applicant@BAGOO.TEST  '), 'new.applicant@bagoo.test');
    assert.equal(ui.normalizeRegistrationEmail('   '), '');
    for (const value of ['Buyer @bagoo.test', 'Buyer@bagoo.test\n', 'Buyer\u200b@bagoo.test']) {
        const normalized = ui.normalizeRegistrationEmail(value);
        assert.equal(ui.validEmailFormat(normalized), false, value);
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
    assert.match(html, /maxlength="10"/i);
});

test('mobile edits block letters, extra digits and invalid starts without changing them into another number', () => {
    for (const value of ['91712345678', '091712345678', '+6391712345678', '0917ABC4567', '０９１７１２３４５６７', '+649171234567', '8171234567', '9171234567\n', '917\n']) {
        const result = ui.mobileInputChange(value);
        assert.equal(result.value, undefined, value);
        assert.ok(result.error, value);
    }
    for (const value of ['9171234567', '09171234567', '+63 917 123 4567', '(0917) 123-4567']) {
        assert.deepEqual(ui.mobileInputChange(value), { value: '+639171234567' });
    }
    assert.deepEqual(ui.mobileInputChange(''), { value: '' });
    assert.deepEqual(ui.mobileInputChange('917'), { value: '+63917' });
    for (const value of ['9', '917123456', '+6391712345678']) assert.ok(ui.mobileNumberError(value), value);
    assert.equal(ui.mobileNumberError(''), undefined);
    assert.match(ui.mobileNumberError('', true), /required/);
    assert.equal(ui.mobileNumberError('+639171234567'), undefined);
    const html = renderToStaticMarkup(createElement(ui.Phone, { value: '+639171234567', onChange() {} }));
    assert.match(html, /value="9171234567"/);
    assert.match(html, /minlength="10"/i);
    assert.match(html, /maxlength="10"/i);
    assert.match(html, /inputMode="numeric"/i);
    const incomplete = renderToStaticMarkup(createElement(ui.Phone, { value: '+63917', onChange() {} }));
    assert.match(incomplete, /exactly 10 digits/);
    assert.match(incomplete, /aria-invalid="true"/);
    assert.match(incomplete, /aria-live="polite"/);
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
    assert.deepEqual(ui.registrationErrors('buyer', { name: 'Combined name is too long.' }), { name: 'Combined name is too long.' });
    assert.deepEqual(ui.registrationErrors('seller', { shop_phone: 'Invalid contact.' }), { phone: 'Invalid contact.' });
    assert.deepEqual(ui.registrationErrors('logistics', { 'vehicle_types.0': 'Invalid vehicle.' }), { vehicle_types: 'Invalid vehicle.' });
    assert.equal(ui.registrationErrorStep('buyer', { first_name: 'Invalid.' }), 1);
    assert.equal(ui.registrationErrorStep('seller', { password: 'Too short.' }), 3);
    assert.equal(ui.registrationErrorStep('courier', { license_number: 'Invalid.' }), 2);
    assert.equal(ui.registrationErrorStep('logistics', { company_email: 'Invalid.' }), 1);
    assert.equal(ui.registrationErrorStep('logistics', { 'vehicle_types.0': 'Invalid.' }), 2);
    assert.equal(ui.registrationErrorStep('logistics', { business_permit: 'Required.' }), 3);
    for (const role of ['buyer', 'seller', 'courier', 'logistics']) {
        for (const field of ['first_name', 'middle_initial', 'last_name', 'name']) {
            assert.equal(ui.registrationErrorStep(role, { [field]: 'Invalid name.' }), 1);
        }
    }
});

const limits = { today: '2026-10-12', past_maximum: '2026-10-11', adult_maximum: '2008-10-12' };
const policies = { terms_version: '2026-10-12', privacy_version: '2026-10-12', terms_url: '/terms/2026-10-12', privacy_url: '/privacy/2026-10-12', required: true };
const identity = role => ({ role, first_name: 'Ana-María', middle_initial: 'J.', last_name: 'O’Connor', email: 'applicant@bagoo.test', birthday: '1999-04-15', phone: '+639171234567', city: 'Makati', address: '12 Bagoo Street', sex: 'Female', postal_code: '0123',
    password: 'BagooPassword2026!', password_confirmation: 'BagooPassword2026!', shop_name: 'Bagoo Shop', root_category_id: '1', company_name: 'Bagoo Logistics', company_code: 'BGL', fleet_size: '1', vehicle_types: ['motorcycle'], vehicle_type: 'Motorcycle', plate_number: 'AB-12345', license_number: 'N01-12-345678', policy_accepted: true,
    id_document: new File(['%PDF-1.4'], 'id.pdf', { type: 'application/pdf' }), business_permit: new File(['%PDF-1.4'], 'permit.pdf', { type: 'application/pdf' }), driver_license: new File(['%PDF-1.4'], 'license.pdf', { type: 'application/pdf' }), or_cr_document: new File(['%PDF-1.4'], 'registration.pdf', { type: 'application/pdf' }) });

test('each personal name immediately rejects digits and hidden or unsupported characters while allowing real punctuation', () => {
    for (const label of ['First name', 'Last name']) {
        for (const value of ['Ana-María', 'José', 'Peña', 'Muñoz', 'Peña-O’Connor', 'Pen\u0303a-Santos', "O'Hara", 'O’Connor', 'Anne-Marie', 'Santos Jr.', 'Dela Cruz', '  Jose\u0301  ']) {
            assert.equal(ui.personalNameError(value, label), undefined, value);
        }
        for (const value of ['Ana1', '3', 'Ana@', 'Ana#', 'Ana😀', '<script>', '---', 'Ana\u200b', 'Ana\u034f', 'Ana\ufe0f', 'Ana\n']) {
            assert.ok(ui.personalNameError(value, label), value);
        }
        assert.ok(ui.personalNameError('', label));
        assert.equal(ui.personalNameError('   ', label, false), undefined);
        assert.ok(ui.personalNameError('A', label));
        assert.equal(ui.personalNameError('Al', label), undefined);
        assert.equal(ui.personalNameError('A'.repeat(50), label), undefined);
        assert.ok(ui.personalNameError('A'.repeat(51), label));
    }
    assert.equal(ui.personalNameError('M', 'Middle name', false), undefined);
});

test('optional middle initial accepts one letter and an optional period with a maximum of two characters', () => {
    for (const value of ['', '  ', 'M', 'm.', 'Ñ', 'É.', '  M.  ', 'E\u0301.']) assert.equal(ui.middleInitialError(value), undefined, value);
    for (const value of ['MM', 'M.M', 'M..', 'Middle', '1', 'M1', '.', 'M-', 'M\n', 'M\u200b', 'M\u034f']) assert.ok(ui.middleInitialError(value), value);
    const html = renderToStaticMarkup(createElement(ui.PersonalNames, { values: identity('buyer'), onChange() {} }));
    const input = html.match(/<input[^>]*id="middle_initial"[^>]*>/)[0];
    assert.match(input, /maxlength="2"/i);
    assert.match(input, /minlength="1"/i);
    assert.doesNotMatch(input, /required=/);
    for (const role of ['buyer', 'seller', 'courier', 'logistics']) {
        assert.match(ui.registrationInputErrors({ ...identity(role), middle_initial: 'MM' }, limits, 1).middle_initial, /one letter/);
        assert.equal(ui.registrationInputErrors({ ...identity(role), middle_initial: 'M' }, limits, 1).middle_initial, undefined);
    }
});

test('invalid typed and pasted names display accessible inline feedback and preserve the entered text', () => {
    for (const field of ['first_name', 'middle_initial', 'last_name']) {
        const html = renderToStaticMarkup(createElement(ui.PersonalNames, {
            values: { first_name: 'Ana', middle_initial: '', last_name: 'Santos', [field]: 'Ana123' }, onChange() {}, accent: 'emerald',
        }));
        const input = html.match(new RegExp('<input[^>]*id="' + field + '"[^>]*>'))[0];
        assert.match(input, /value="Ana123"/);
        assert.match(input, /aria-invalid="true"/);
        assert.match(input, new RegExp('aria-describedby="' + field + '-error"'));
        assert.match(html, field === 'middle_initial' ? /Enter one letter/ : /Numbers and other symbols are not allowed/);
        assert.match(html, /aria-live="polite"/);
        assert.match(input, field === 'middle_initial' ? /maxlength="2"/i : /maxlength="50"/i);
    }
    const valid = renderToStaticMarkup(createElement(ui.PersonalNames, { values: { ...identity('courier'), last_name: 'Peña-O’Connor' }, onChange() {} }));
    assert.doesNotMatch(valid, /aria-invalid="true"/);
    assert.match(valid, /value="Peña-O’Connor"/);
    assert.match(valid, /Middle initial \(optional\)/);
    const empty = renderToStaticMarkup(createElement(ui.PersonalNames, { values: { first_name: '', middle_initial: '', last_name: '' }, onChange() {} }));
    assert.doesNotMatch(empty, /is required|aria-invalid="true"/);
    assert.doesNotMatch(empty, /personal-name-help|First and last names:|Total limit:/);
    assert.match(empty, /sm:col-span-3[^>]*><label[^>]*for="middle_initial"/);
    const longName = 'A'.repeat(51);
    const overlong = renderToStaticMarkup(createElement(ui.PersonalNames, { values: { first_name: longName, middle_initial: '', last_name: 'Santos' }, onChange() {} }));
    assert.match(overlong, new RegExp('value="' + longName + '"'));
    assert.match(overlong, /First name must contain 2 to 50 characters/);
});

test('incomplete and malformed identities get field errors before any request or unreadable response', async () => {
    let requests = 0;
    globalThis.fetch = async () => { requests++; return reply(200, '<html>Unavailable</html>'); };
    for (const role of ['buyer', 'seller', 'courier', 'logistics']) {
        await assert.rejects(ui.checkRegistration({ ...identity(role), first_name: 'Ana123', last_name: '', email: '12341234', birthday: '0412-02-31', phone: '12341234123', city: '', address: '' }, limits, 1), error => {
            assert.match(error.fields.first_name, /Numbers/);
            assert.match(error.fields.last_name, /required/);
            assert.match(error.fields.email, /complete email/);
            if (role !== 'buyer') assert.match(error.fields.birthday, /real birth date/);
            if (role === 'courier') {
                assert.match(error.fields.phone, /10 digits after \+63/);
                assert.match(error.fields.city, /required/);
                assert.match(error.fields.address, /required/);
            }
            assert.doesNotMatch(error.message, /could not read/);
            return true;
        });
        await assert.rejects(ui.checkRegistration({ ...identity(role), first_name: '', middle_initial: '', last_name: '' }, limits, 1), error => Boolean(error.fields.first_name && error.fields.last_name && !error.fields.middle_initial));
    }
    for (const sex of ['', '   ', undefined, null, 1, ['Female']]) {
        assert.match(ui.registrationInputErrors({ ...identity('buyer'), sex }, limits, undefined, true).sex, /required/);
    }
    assert.equal(requests, 0);
});

test('valid names still require server confirmation and keep raw details for authoritative validation', async () => {
    let requests = 0;
    globalThis.fetch = async (path, options) => {
        requests++;
        assert.equal(path, '/register/validate');
        assert.equal(options.body.get('first_name'), '  Ana-María  ');
        assert.equal(options.body.get('last_name'), 'O’Connor');
        return reply(200, { valid: true });
    };
    await ui.checkRegistration({ ...identity('courier'), first_name: '  Ana-María  ' }, limits, 1);
    assert.equal(requests, 1);
    globalThis.fetch = async () => reply(422, { errors: { middle_initial: ['Enter a valid middle initial.'] } });
    await assert.rejects(ui.checkRegistration(identity('courier'), limits, 1), error => error.fields.middle_initial === 'Enter a valid middle initial.');
    for (const body of ['<html>Unavailable</html>', {}, { valid: false }]) {
        globalThis.fetch = async () => reply(200, body);
        await assert.rejects(ui.checkRegistration(identity('courier'), limits, 1));
    }
});

test('an already signed-in registration session shows the sign-out action without claiming verification success', async () => {
    const message = 'You are already signed in. Sign out before continuing with this form.';
    globalThis.fetch = async () => reply(409, { message });
    await assert.rejects(ui.checkRegistration(identity('courier'), limits, 1), error => error.status === 409 && error.message === message);
    await assert.rejects(ui.requestOtp('send', { email: 'new-person@bagoo.test' }), error => error.status === 409 && error.message === message);
});

test('date and combined-name boundaries are checked without truncation or blocking future steps', () => {
    for (const birthday of ['0412-02-31', '2025-02-29', '0000-01-01', '2000-13-01', '2026-10-12', '2008-10-13', '1999-04-15\n']) {
        assert.ok(ui.registrationInputErrors({ ...identity('courier'), birthday }, limits, 1).birthday, birthday);
    }
    for (const birthday of ['2000-02-29', '2008-10-12']) {
        assert.equal(ui.registrationInputErrors({ ...identity('courier'), birthday }, limits, 1).birthday, undefined, birthday);
    }
    const buyer = { ...identity('buyer'), birthday: '' };
    assert.equal(ui.registrationInputErrors(buyer, limits, 2).birthday, undefined);
    const names = { ...identity('buyer'), first_name: 'A'.repeat(49), middle_initial: '', last_name: 'B'.repeat(50) };
    assert.equal(ui.registrationInputErrors(names, limits, 1).name, undefined);
    assert.ok(ui.registrationInputErrors({ ...names, first_name: 'A'.repeat(50) }, limits, 1).name);
    assert.deepEqual(ui.registrationInputErrors({ ...identity('seller'), birthday: '', phone: '', city: '', address: '' }, limits, 2), { phone: 'Mobile number is required.', city: 'City or municipality is required.', address: 'Street address is required.' });
    const courierStep2 = ui.registrationInputErrors({ ...identity('courier'), first_name: '', birthday: '', email: '' }, limits, 2);
    assert.deepEqual(courierStep2, {});
    for (const phone of ['+639171234567\n', '0917ABC4567', '+649171234567', '091712345678']) {
        assert.ok(ui.registrationInputErrors({ ...identity('courier'), phone }, limits, 1).phone, phone);
    }
});

test('every account role uses mobile-only input while public business contacts may use landlines', () => {
    for (const role of ['buyer', 'seller', 'courier', 'logistics']) {
        const step = ['courier', 'logistics'].includes(role) ? 1 : 2;
        for (const phone of ['09171234567', '+639171234567', '+63 917 123 4567']) {
            assert.equal(ui.registrationInputErrors({ ...identity(role), phone }, limits, step).phone, undefined, role);
        }
        for (const phone of ['(02) 8123-4567', '0917123456', '091712345678', '0917ABC4567']) {
            assert.ok(ui.registrationInputErrors({ ...identity(role), phone }, limits, step).phone, role + ': ' + phone);
        }
    }
    assert.equal(ui.businessContactError('(02) 8123-4567', true), undefined);
});

test('all registration pages preserve the portal shell and controlled form feedback', async () => {
    for (const [name, Component] of [['Buyer', ui.Buyer], ['Seller', ui.Seller], ['Courier', ui.Courier], ['Logistics', ui.Logistics]]) {
        let view;
        const props = { auth: { user: null }, flash: {}, errors: {}, birthDateLimits: limits, policies, masterCategories: [{ id: 1, name: 'Pet Supplies' }] };
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
        assert.match(result.body, /<input[^>]*id="email"[^>]*aria-describedby="registration-email-feedback"[^>]*aria-invalid="false"/);
        assert.match(result.body, /id="registration-email-feedback"[^>]*role="status"[^>]*aria-live="polite"/);
        assert.doesNotMatch(result.body, /Email is available|Checking email/);
        for (const field of ['first_name', 'last_name', 'middle_initial']) assert.match(result.body, new RegExp('<input[^>]*name="' + field + '"'));
        assert.match(result.body, /name="first_name"[^>]*required=""/);
        assert.match(result.body, /name="last_name"[^>]*required=""/);
        assert.doesNotMatch(result.body, /name="middle_initial"[^>]*required/);
        assert.match(result.body, /<select[^>]*name="sex"[^>]*required=""/);
        assert.ok(result.body.indexOf('name="last_name"') < result.body.indexOf('name="sex"'));
        assert.ok(result.body.indexOf('name="sex"') < result.body.indexOf('name="email"'));
        assert.doesNotMatch(result.body, /personal-name-help|First and last names:|Total limit:/);
        assert.match(result.body.match(/<input[^>]*type="email"[^>]*>/)[0], /maxlength="255"/i);
        assert.doesNotMatch(result.body, /Min 8 characters|font-mono|Full Legal Name|<input[^>]*name="name"/);
    }
});

test('password feedback requires every character group, preserves passphrase spaces and enforces both limits', () => {
    for (const value of ['Aa1!' + 'a'.repeat(4), 'Aa1!' + 'é'.repeat(124), '  Bagoo passphrase 2026!  ']) assert.equal(ui.passwordError(value), undefined, value);
    for (const value of ['Aa1!' + 'a'.repeat(3), 'Aa1!' + 'a'.repeat(125), 'password1234!', 'PASSWORD1234!', 'BagooPassword!', 'BagooPassword1', 'BagooPassword1 ', 'BagooPassword1!\n']) assert.ok(ui.passwordError(value), value);
});

test('company, vehicle and document fields have explicit bounds without changing invalid input into valid values', () => {
    for (const value of ['BG', 'bagoo', 'A'.repeat(10)]) assert.equal(ui.companyInitialsError(value), undefined);
    for (const value of ['B', 'A'.repeat(11), 'BG12', 'BG-PH', 'ＢＧ', 'BG\n']) assert.ok(ui.companyInitialsError(value));
    for (const value of ['AB12345', 'AB-12345', '1234 AB', 'ABC-123']) assert.equal(ui.plateNumberError(value, 'Motorcycle'), undefined, value);
    for (const value of ['ABC12', 'ABC-123', 'ABC1234']) assert.equal(ui.plateNumberError(value, 'Sedan / Van'), undefined, value);
    for (const value of ['ABC-1234', 'AB--12345', '123456', 'ＡＢ12345', 'AB-123456']) assert.ok(ui.plateNumberError(value, 'Motorcycle'), value);
    for (const value of ['N01-12-345678', 'n0112345678']) assert.equal(ui.licenseNumberError(value), undefined, value);
    for (const value of ['N01--12-345678', 'N01-12-34567', 'N01123456789', '１２３４', '12345678901']) assert.ok(ui.licenseNumberError(value), value);
    const exact = new File([' '.repeat(ui.documentMaxBytes)], 'evidence.PDF', { type: 'application/pdf' });
    assert.equal(ui.documentError(exact, true), undefined);
    for (const file of [new File([], 'empty.pdf', { type: 'application/pdf' }), new File([' '.repeat(ui.documentMaxBytes + 1)], 'large.pdf', { type: 'application/pdf' }), new File(['script'], 'script.svg', { type: 'image/svg+xml' }), new File(['script'], 'script.pdf', { type: 'text/html' })]) assert.ok(ui.documentError(file));
});

test('overlong paste is rejected as a whole, while operational code edits retain invalid Unicode for feedback', () => {
    for (const [name, maxLength, pasted] of [['plate_number', 8, 'AB-123456'], ['company_code', 10, 'ABCDEFGHIJK'], ['license_number', 13, 'N01-12-3456789'], ['password', 128, 'Aa1!' + 'a'.repeat(125)], ['postal_code', 4, '12345']]) {
        let blocked = false;
        let feedback;
        const target = { tagName: 'INPUT', type: name === 'password' ? 'password' : 'text', name, maxLength, value: '', selectionStart: 0, selectionEnd: 0 };
        ui.rejectOverlongRegistrationPaste({ target, clipboardData: { getData: () => pasted }, preventDefault() { blocked = true; } }, (field, message) => { feedback = { field, message }; });
        assert.equal(blocked, true, name);
        assert.equal(target.value, '');
        assert.equal(feedback.field, name);
        assert.match(feedback.message, /No pasted text was added/);
    }
    for (const [name, type, maxLength, pasted] of [['email', 'email', 255, '  ' + 'a'.repeat(242) + '@bagoo.test  '], ['phone', 'tel', 10, '+63 917 123 4567']]) {
        let blocked = false;
        ui.rejectOverlongRegistrationPaste({ target: { tagName: 'INPUT', name, type, maxLength, value: '', selectionStart: 0, selectionEnd: 0 }, clipboardData: { getData: () => pasted }, preventDefault() { blocked = true; } }, () => {});
        assert.equal(blocked, false);
    }
    assert.equal(ui.uppercaseOperationalCode('ab-12345'), 'AB-12345');
    assert.equal(ui.uppercaseOperationalCode('ßＢＧ'), 'ßＢＧ');
    assert.ok(ui.companyInitialsError(ui.uppercaseOperationalCode('ßＢＧ')));
});

test('each role checks sex on the name step and postal values on the address step before an OTP request', async () => {
    let requests = 0;
    globalThis.fetch = async () => { requests++; return reply(200, { valid: true }); };
    for (const role of ['buyer', 'seller', 'courier', 'logistics']) {
        const step = role === 'courier' ? 1 : role === 'logistics' ? 3 : 2;
        assert.equal(ui.registrationInputErrors(identity(role), limits, step).postal_code, undefined);
        assert.equal(ui.registrationInputErrors({ ...identity(role), postal_code: '' }, limits, step).postal_code, undefined);
        for (const sex of ['', '   ', undefined, null, 1, ['Female']]) {
            assert.match(ui.registrationInputErrors({ ...identity(role), sex }, limits, 1).sex, /required/);
            await assert.rejects(ui.checkRegistration({ ...identity(role), sex }, limits, 1), error => Boolean(error.fields.sex));
        }
        for (const value of ['123', '12345', '１２３４', '1e03']) {
            await assert.rejects(ui.checkRegistration({ ...identity(role), postal_code: value }, limits, step), error => Boolean(error.fields.postal_code));
        }
        await assert.rejects(ui.checkRegistration({ ...identity(role), sex: 'Unknown' }, limits, 1), error => Boolean(error.fields.sex));
        if (step !== 1) assert.equal(ui.registrationInputErrors({ ...identity(role), sex: 'Unknown' }, limits, step).sex, undefined);
        assert.equal(ui.registrationErrorStep(role, { sex: 'Invalid' }), 1);
        assert.equal(ui.registrationErrorStep(role, { postal_code: 'Invalid' }), step);
        assert.ok(ui.registrationInputErrors({ ...identity(role), policy_accepted: false }, limits).policy_accepted);
    }
    assert.equal(requests, 0);
});

test('sex is required while postal code preserves leading zeroes and remains optional', () => {
    const sex = renderToStaticMarkup(createElement(ui.RegistrationSexField, { value: 'Female', onChange() {} }));
    assert.doesNotMatch(sex, /Sex \(optional\)/);
    assert.match(sex, /<select[^>]*name="sex"[^>]*required=""[^>]*aria-required="true"/);
    const html = renderToStaticMarkup(createElement(ui.AddressSelector, {
        values: { province: '', city: '', municipality: '', barangay: '', address: '' }, onChange() {},
        postalCodeField: createElement(ui.RegistrationPostalCodeField, { value: '0123', onChange() {} }),
    }));
    assert.match(html, /Postal code \(optional\)/);
    assert.doesNotMatch(html, /name="sex"/);
    assert.match(html, /sm:col-span-7[\s\S]*name="barangay"[\s\S]*sm:col-span-5[\s\S]*name="postal_code"[\s\S]*sm:col-span-12[\s\S]*name="address"/);
    const postal = html.match(/<input[^>]*name="postal_code"[^>]*>/)[0];
    assert.match(postal, /value="0123"/);
    assert.match(postal, /maxlength="4"/i);
    assert.match(postal, /inputMode="numeric"/i);
    assert.doesNotMatch(postal, /required=/);
});

test('policy acknowledgement starts unchecked with accessible versioned documents and field feedback', () => {
    const html = renderToStaticMarkup(createElement(ui.PolicyAcknowledgement, { policies, accepted: false, onChange() {}, error: 'Please read and agree.' }));
    const checkbox = html.match(/<input[^>]*type="checkbox"[^>]*>/)[0];
    assert.doesNotMatch(checkbox, /checked=/);
    assert.match(checkbox, /required=""/);
    assert.match(checkbox, /aria-invalid="true"/);
    assert.match(html, /href="\/terms\/2026-10-12"/);
    assert.match(html, /href="\/privacy\/2026-10-12"/);
    assert.match(html, /Please read and agree/);
    const accepted = renderToStaticMarkup(createElement(ui.PolicyAcknowledgement, { policies, accepted: true, onChange() {} }));
    assert.match(accepted.match(/<input[^>]*type="checkbox"[^>]*>/)[0], /checked=""/);
    assert.deepEqual(ui.registrationErrors('courier', { terms_version: 'Reload and agree.' }), { policy_accepted: 'Reload and agree.' });
});

test('correction controls show separated names, a narrower middle field and the server limits', () => {
    const fields = [
        ['first_name', 'First name', 2, 50, 'text'], ['middle_name', 'Middle name / initial', 1, 50, 'text'], ['last_name', 'Last name', 2, 50, 'text'], ['sex', 'Sex', null, 255, 'select'], ['postal_code', 'Postal code', 4, 4, 'text'],
    ].map(([key, label, min_length, max_length, type]) => ({ key, label, min_length, max_length, type, required: ['first_name', 'last_name', 'sex'].includes(key), options: key === 'sex' ? ['Female', 'Male', 'Other'] : [], input_mode: key === 'postal_code' ? 'numeric' : null }));
    const values = { first_name: 'Ana', middle_name: 'J.', last_name: 'Santos', sex: 'Female', postal_code: '0123' };
    const html = renderToStaticMarkup(createElement(ui.ApplicationFields, { application: { fields, values, errors: {}, can_correct: true }, values, errors: {}, onChange() {} }));
    assert.doesNotMatch(html, /<input[^>]*name="name"/);
    assert.match(html, /sm:col-span-3[^>]*><label[^>]*for="application-middle_name"/);
    assert.match(html.match(/<input[^>]*name="first_name"[^>]*>/)[0], /maxlength="50"/i);
    assert.match(html.match(/<input[^>]*name="postal_code"[^>]*>/)[0], /maxlength="4"/i);
    assert.match(html, /<select[^>]*name="sex"[^>]*required=""/);
});

test('first Google signup collects the same buyer details while relying on the verified provider email', async () => {
    let view;
    const Component = ui.Google;
    const props = { auth: { user: null }, flash: {}, errors: {}, identity: { name: 'Buyer Profile', email: 'verified@bagoo.test' }, birthDateLimits: limits, policies, registrationNonce: 'server-nonce' };
    const result = await createInertiaApp({ page: { component: 'Auth/GoogleRegistration', props, url: '/auth/google/register', version: null }, resolve: () => Component, setup: ({ App, props }) => { view = createElement(App, props); return view; }, render: () => renderToStaticMarkup(view) });
    for (const field of ['first_name', 'middle_initial', 'last_name', 'birthday', 'phone', 'postal_code', 'policy_accepted']) assert.match(result.body, new RegExp('<input[^>]*name="' + field + '"'));
    assert.match(result.body, /<select[^>]*name="sex"[^>]*required=""[^>]*aria-required="true"/);
    assert.ok(result.body.indexOf('name="last_name"') < result.body.indexOf('name="sex"'));
    assert.ok(result.body.indexOf('name="sex"') < result.body.indexOf('name="birthday"'));
    assert.ok(result.body.indexOf('name="barangay"') < result.body.indexOf('name="postal_code"'));
    assert.ok(result.body.indexOf('name="postal_code"') < result.body.indexOf('name="address"'));
    assert.doesNotMatch(result.body, /personal-name-help|First and last names:|Total limit:/);
    assert.match(result.body, /verified@bagoo.test/);
    assert.doesNotMatch(result.body, /<input[^>]*name="email"|<input[^>]*name="password"/);
    const values = { ...identity('buyer'), email: '', password: '', password_confirmation: '', birthday: '', phone: '', city: '', address: '' };
    assert.deepEqual(ui.registrationInputErrors(values, limits, undefined, true), {});
    assert.ok(ui.registrationInputErrors({ ...values, postal_code: '12345' }, limits, undefined, true).postal_code);
});
