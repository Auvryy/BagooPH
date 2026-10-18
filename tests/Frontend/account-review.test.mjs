import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { buildSync } from 'esbuild';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createInertiaApp } from '@inertiajs/react';

const root = resolve(import.meta.dirname, '../..');
const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-account-review-'));
after(() => { rmSync(temporary, { recursive: true, force: true }); delete globalThis.route; });
const bundle = buildSync({ stdin: { contents: [
    "export { default as Status } from '@/Pages/Auth/AccountStatus';",
    "export { default as RequestForm } from '@/Components/AccountReviewRequestForm';",
    "export { default as AdminReview } from '@/Pages/Admin/AccountReview';",
].join('\n'), resolveDir: root, loader: 'tsx' }, bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', write: false,
loader: { '.css': 'empty' } });
const modulePath = join(temporary, 'review.mjs');
writeFileSync(modulePath, bundle.outputFiles[0].text);
const ui = await import(pathToFileURL(modulePath).href);
globalThis.route = (name, value) => name ? '/' + name.replaceAll('.', '/') + (typeof value === 'number' ? '/' + value : '') : { current: () => false, has: () => true };

const actor = role => ({ id: 7, name: 'Ana Santos', email: 'shared@bagoo.test', role, status: 'suspended', kyc_status: 'approved' });
const source = { type: 'account', id: 7, name: 'Your account', kind: 'restriction', status: 'suspended', reason: 'Please correct your account details.', source_token: 'a'.repeat(64), pending_request_id: null };
async function render(Component, props, user = actor('buyer')) {
    let view;
    const response = await createInertiaApp({
        page: { component: 'Auth/AccountStatus', props: { auth: { user }, flash: {}, notificationSummary: { available: true, unread: 0 }, ...props }, url: '/account/status', version: null },
        resolve: () => Component, setup: ({ App, props }) => { view = createElement(App, props); return view; }, render: () => renderToStaticMarkup(view),
    });
    return response.body;
}

for (const role of ['buyer', 'seller', 'courier', 'logistics', 'admin']) {
    test(`${role} restriction shows clear status, review access and no operational dashboard`, async () => {
        const html = await render(ui.Status, { status: { title: 'Your account is suspended', message: 'Request a review below.', account: actor(role), sources: [source], requests: [],
            can_correct_application: false, can_correct_identity: true, can_open_portal: false, email_update: { pending: true, attempts: 1 } } }, actor(role));
        assert.match(html, /Your account is suspended/);
        assert.match(html, /Request review/);
        assert.match(html, /Correct reviewed details/);
        assert.match(html, /send the email update yet/);
        assert.doesNotMatch(html, /Open dashboard|Reactivate account|These credentials do not match/);
    });
}

test('an existing pending request replaces the duplicate request button with its saved state', async () => {
    const html = await render(ui.RequestForm, { source: { ...source, pending_request_id: 23 } });
    assert.match(html, /Request #23 is waiting for review/);
    assert.doesNotMatch(html, /<form|Request review|Send review request/);
});

test('review history escapes explanations and shows a saved admin response', async () => {
    const html = await render(ui.Status, { status: { title: 'Your application was rejected', message: 'Read the feedback.', account: actor('buyer'), sources: [],
        requests: [{ id: 3, subject_type: 'account', subject_id: 7, message: '<script>Unsafe explanation</script>', status: 'declined', created_at: '2026-10-18T00:00:00Z', resolved_at: '2026-10-18T01:00:00Z',
            decision: { action: 'decline', reason: 'Please correct the document first.', reviewer: 'Reviewer', reviewed_at: '2026-10-18T01:00:00Z' } }],
        can_correct_application: true, can_correct_identity: false, can_open_portal: false, email_update: null } });
    assert.match(html, /Request declined/);
    assert.match(html, /Please correct the document first/);
    assert.match(html, /Correct application/);
    assert.match(html, /&lt;script&gt;Unsafe explanation&lt;\/script&gt;/);
    assert.doesNotMatch(html, /<script>Unsafe/);
});

test('admin reopening explains that normal evidence approval still applies', async () => {
    const html = await render(ui.AdminReview, { endpoint: '/admin/account-reviews/3/decision', review: {
        request: { id: 3, message: 'Please review the application again.', status: 'pending', created_at: '2026-10-18T00:00:00Z', decision: null },
        owner: actor('buyer'), source_reason: 'The document needs correction.', stale: false, actions: ['decline', 'reopen_application'], activity: null, review_token: 'a'.repeat(64),
    } }, { ...actor('admin'), status: 'active' });
    assert.match(html, /Return application to review/);
    assert.match(html, /does not approve it/);
    assert.match(html, /normal evidence review still applies/);
    assert.doesNotMatch(html, /Reactivate account|Approve application now/);
});
