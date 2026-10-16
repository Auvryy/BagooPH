import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createInertiaApp, router } from '@inertiajs/react';
import axios from 'axios';

const root = resolve(import.meta.dirname, '../..');
const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-notifications-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = await build({
    stdin: { contents: `export { default as Notifications } from '@/Pages/Notifications/Index';
        export { default as Bell } from '@/Components/NotificationLink';
        export { default as Menu } from '@/Components/NotificationMenu';
        export { default as useAction } from '@/hooks/useNotificationAction';`, resolveDir: root, loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', write: false, loader: { '.css': 'empty' },
    plugins: [{ name: 'notification-state-probes', setup(builder) {
        builder.onResolve({ filter: /^react$/ }, args => /\/(?:NotificationMenu\.tsx|useNotificationAction\.ts)$/.test(args.importer)
            ? { path: 'hooks', namespace: 'notification-hooks' } : undefined);
        builder.onLoad({ filter: /.*/, namespace: 'notification-hooks' }, () => ({ contents: `import React from 'react'; export * from 'react';
            export const useState = initial => globalThis.notificationHarness ? globalThis.notificationHarness.state(initial) : React.useState(initial);
            export const useRef = initial => globalThis.notificationHarness ? globalThis.notificationHarness.state(() => ({ current: initial }))[0] : React.useRef(initial);
            export const useEffect = (effect, deps) => globalThis.notificationHarness ? globalThis.notificationHarness.effect(effect, deps) : React.useEffect(effect, deps);`, resolveDir: root }));
        builder.onResolve({ filter: /^@headlessui\/react$/ }, args => args.importer.endsWith('/NotificationMenu.tsx')
            ? { path: 'popover', namespace: 'notification-popover' } : undefined);
        builder.onLoad({ filter: /.*/, namespace: 'notification-popover' }, () => ({ contents: `import { createElement } from 'react';
            import { Popover as RealPopover, PopoverButton as RealButton, PopoverPanel as RealPanel } from '@headlessui/react';
            export const Popover = props => { const h = globalThis.notificationHarness; return h ? createElement('div', { className: props.className }, props.children({ open: h.open, close: h.close })) : createElement(RealPopover, props); };
            export const PopoverButton = props => { const h = globalThis.notificationHarness; if (!h) return createElement(RealButton, props); h.trigger = { ...props, onClick: () => { h.open = !h.open; } }; return createElement('button', { ...props, 'aria-expanded': h.open }); };
            export const PopoverPanel = props => { const h = globalThis.notificationHarness; if (!h) return createElement(RealPanel, props); h.panel = props; return h.open ? createElement('section', { className: props.className, 'aria-labelledby': props['aria-labelledby'] }, props.children) : null; };`, resolveDir: root }));
        builder.onResolve({ filter: /^react\/jsx-runtime$/ }, args => /\/(?:NotificationMenu|Notifications\/Index)\.tsx$/.test(args.importer)
            ? { path: 'jsx', namespace: 'notification-jsx' } : undefined);
        builder.onLoad({ filter: /.*/, namespace: 'notification-jsx' }, () => ({ contents: `import { jsx as realJsx, jsxs as realJsxs, Fragment } from 'react/jsx-runtime'; export { Fragment };
            const track = (fn, type, props, key) => { if (globalThis.notificationHarness && props.onClick) globalThis.notificationHarness.handlers.push({ type, props }); return fn(type, props, key); };
            export const jsx = (type, props, key) => track(realJsx, type, props, key);
            export const jsxs = (type, props, key) => track(realJsxs, type, props, key);`, resolveDir: root }));
    } }],
});
const modulePath = join(temporary, 'notifications.mjs');
writeFileSync(modulePath, bundle.outputFiles[0].text);
const ui = await import(pathToFileURL(modulePath).href);
globalThis.route = name => name ? `/${name.replaceAll('.', '/')}` : { current: () => false, has: () => true };
after(() => delete globalThis.route);

const notice = (id, read_at = null) => ({ id, type: 'governance-event', data: { title: `Update ${id}`, body: 'Your saved account update.' },
    created_at: '2026-10-16T00:00:00Z', read_at, href: '/pending-approval' });
const page = (data = []) => ({ data, current_page: 1, last_page: 1, prev_page_url: null, next_page_url: null });
const actor = role => ({ id: 7, name: 'Ana Santos', email: `${role}@bagoo.test`, role, status: 'active', kyc_status: 'approved', courier_profile: { is_available: true },
    activeHub: { name: 'Assigned Bayan Hub', code: 'BGO-HUB' }, allHubs: [], logisticsCompany: { name: 'Bagoo Fleet' } });
const notificationSummary = { available: true, unread: 3 };
async function render(Component, props = {}, user = actor('buyer')) {
    let view;
    const response = await createInertiaApp({
        page: { component: globalThis.notificationHarness?.component ?? 'Notifications/Index', props: { auth: { user }, flash: {}, cartCount: 0,
            notificationSummary, ...props }, url: '/notifications', version: null },
        resolve: () => Component, setup: ({ App, props }) => { view = createElement(App, props); return view; }, render: () => renderToStaticMarkup(view),
    });
    return response.body;
}
const roles = [
    ['buyer', 'buyer', 'Marketplace'], ['seller', 'dashboard', 'Seller Centre'], ['courier', 'courier', 'Rider navigation'],
    ['company', 'dashboard', 'Logistics Hub'], ['handler', 'dashboard', 'Logistics Hub'], ['admin', 'dashboard', 'Admin Portal'],
];
for (const [kind, portal, shell] of roles) {
    test(`${kind} notification list retains its portal shell, unread styling, filters and history pages`, async () => {
        const user = actor(['company', 'handler'].includes(kind) ? 'logistics' : kind);
        const html = await render(ui.Notifications, { portal, accountUrl: '/dashboard', available: true, filter: 'unread', isOnline: true,
            scope: { company: 'Bagoo Fleet', hub: 'Assigned Bayan Hub', hubCode: 'BGO-HUB' },
            notices: { ...page([notice('owned'), notice('read', '2026-10-16T00:01:00Z')]), last_page: 2, next_page_url: '/notifications?filter=unread&page=2' } }, user);
        assert.match(html, new RegExp(shell));
        assert.match(html, /aria-label="Notifications, 3 unread"/);
        assert.match(html, /aria-label="Unread"/);
        assert.match(html, /border-rose-300 bg-rose-50/);
        assert.match(html, /Notification filters/);
        assert.match(html, /href="\/notifications\?filter=unread&amp;page=2"/);
        assert.match(html, /Update owned/);
        assert.match(html, /View update/);
        if (kind === 'courier') {
            assert.match(html, /aria-label="Rider duty" aria-checked="true"/);
            assert.match(html, /Assigned Bayan Hub/);
            assert.match(html, /BGO-HUB/);
            assert.match(html, /rounded-\[8px\]/);
        }
        assert.doesNotMatch(html, />My account<|min-h-screen bg-\[#FFFAFB\]/);
        const empty = await render(ui.Notifications, { portal, accountUrl: '/dashboard', available: true, filter: 'unread', notices: page() }, user);
        assert.match(empty, new RegExp(shell));
        assert.match(empty, /min-h-60 flex-col items-center justify-center/);
        assert.match(empty, /You have no unread updates/);
        const unavailable = await render(ui.Notifications, { portal, accountUrl: '/dashboard', available: false, filter: 'unread', notices: null,
            notificationSummary: { available: false, unread: null } }, user);
        assert.match(unavailable, new RegExp(shell));
        assert.match(unavailable, /Notifications are temporarily unavailable/);
        assert.match(unavailable, /href="\/notifications\?filter=unread"/);
        assert.doesNotMatch(unavailable, /You have no notifications yet|0 unread updates/);
    });
}

test('every authenticated role has a bell button and guests have no notification controls', async () => {
    for (const [kind] of roles) {
        const html = await render(ui.Bell, {}, actor(['company', 'handler'].includes(kind) ? 'logistics' : kind));
        assert.match(html, /<button[^>]*aria-label="Notifications, 3 unread"/);
        assert.match(html, /aria-expanded="false"/);
        assert.doesNotMatch(html, /<a[^>]*href="\/notifications"/);
    }
    assert.equal(await render(ui.Bell, {}, null), '');
});

test('held workers retain the account gateway and owned updates without operational navigation or duty controls', async () => {
    for (const role of ['seller', 'courier', 'logistics', 'admin']) {
        const html = await render(ui.Notifications, { portal: 'account', accountUrl: '/pending-approval', available: true, filter: 'all', notices: page([notice('held')]) },
            { ...actor(role), status: 'suspended' });
        assert.match(html, /Account status/);
        assert.match(html, /href="\/pending-approval"/);
        assert.match(html, /Update held/);
        assert.doesNotMatch(html, /Rider navigation|Rider duty|Seller Centre|Admin Portal|Scan Station/);
    }
});

const tick = () => new Promise(resolve => setImmediate(resolve));
const text = node => Array.isArray(node) ? node.map(text).join('') : typeof node === 'object' && node ? text(node.props?.children) : String(node ?? '');
const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
class Harness {
    index = 0; values = []; effects = []; pendingEffects = []; handlers = []; open = false; closed = 0; dirty = false; component = 'Seller/Dashboard';
    close = () => { this.open = false; this.closed++; };
    state(initial) {
        const index = this.index++;
        if (!Object.hasOwn(this.values, index)) this.values[index] = typeof initial === 'function' ? initial() : initial;
        return [this.values[index], next => { const value = typeof next === 'function' ? next(this.values[index]) : next; if (!Object.is(value, this.values[index])) this.dirty = true; this.values[index] = value; }];
    }
    effect(callback, deps) {
        const index = this.index++;
        const old = this.effects[index];
        if (!old || deps.some((value, i) => !Object.is(value, old.deps[i]))) this.pendingEffects.push(() => { old?.cleanup?.(); this.effects[index] = { deps, cleanup: callback() }; });
    }
    async render(Component = ui.Menu, props = {}, user = actor('seller')) {
        let html;
        for (let attempt = 0; attempt < 12; attempt++) {
            this.index = 0; this.handlers = []; this.pendingEffects = []; this.dirty = false;
            html = await render(Component, props, user);
            for (const effect of this.pendingEffects) effect();
            await tick();
            if (!this.dirty) return html;
        }
        throw Error('Notification state did not settle.');
    }
    click(label) {
        const handler = this.handlers.find(({ props }) => text(props.children).includes(label));
        assert.ok(handler, `Missing control ${label}`);
        handler.props.onClick();
    }
    dispose() { for (const effect of this.effects) effect?.cleanup?.(); }
}
async function withHarness(run) {
    const h = new Harness();
    const original = { get: axios.get, patch: axios.patch, visit: router.patch, on: router.on, reload: router.reload };
    const gets = [], acks = [], visits = [], reloads = [], listeners = new Map();
    globalThis.notificationHarness = h;
    axios.get = async (...args) => { gets.push(args); return { data: { notices: [notice('first'), notice('second', '2026-10-16T00:01:00Z')], summary: { available: true, unread: 3 } } }; };
    axios.patch = async (...args) => { acks.push(args); return { data: { summary: { available: true, unread: 2 } } }; };
    router.patch = (...args) => { visits.push(args); };
    router.reload = options => { reloads.push(options); };
    router.on = (name, callback) => { listeners.set(name, callback); return () => listeners.delete(name); };
    try { await run({ h, gets, acks, visits, reloads, listeners }); }
    finally { h.dispose(); Object.assign(axios, { get: original.get, patch: original.patch }); Object.assign(router, { patch: original.visit, on: original.on, reload: original.reload }); delete globalThis.notificationHarness; }
}

test('every role loads the compact preview on opening and acknowledges only the displayed unread records', async () => {
    for (const [kind] of roles) await withHarness(async ({ h, gets, acks, reloads }) => {
        const user = actor(['company', 'handler'].includes(kind) ? 'logistics' : kind);
        await h.render(ui.Menu, {}, user);
        assert.equal(gets.length, 0);
        h.trigger.onClick();
        const html = await h.render(ui.Menu, {}, user);
        assert.equal(gets.length, 1);
        assert.equal(reloads.length, 0);
        assert.equal(gets[0][0], '/notifications/preview');
        assert.deepEqual(acks[0][1], { ids: ['first'] });
        assert.match(html, /2 unread/);
        assert.match(html, /Update first/);
        assert.match(html, /View all notifications/);
        assert.deepEqual(h.panel.anchor, { to: 'bottom end', gap: 12, padding: 16 });
        assert.equal(h.panel.focus, true);
        assert.match(h.panel.className, /max-h-.*70dvh/);
        assert.match(h.panel.className, /calc\(100vw-2rem\)/);
        assert.match(html, /min-h-0 overflow-y-auto/);
        assert.match(html, /shrink-0 border-t/);
        assert.match(h.panel.className, kind === 'buyer' ? /rounded-2xl/ : /rounded-\[8px\]/);
        h.click('View all notifications');
        assert.equal(h.open, false);
    });
});

test('reading the preview from the full notification page refreshes its owned list and summary without a full navigation', async () => withHarness(async ({ h, reloads }) => {
    h.component = 'Notifications/Index';
    h.open = true;
    await h.render();
    assert.equal(reloads.length, 1);
    assert.deepEqual(reloads[0], { only: ['notificationSummary', 'notices'] });
}));

test('a fresh successful preview restores the badge after an unavailable shared summary', async () => withHarness(async ({ h }) => {
    const props = { notificationSummary: { available: false, unread: null } };
    assert.match(await h.render(ui.Menu, props), /Notifications temporarily unavailable/);
    h.open = true;
    const html = await h.render(ui.Menu, props);
    assert.match(html, /Notifications, 2 unread/);
    assert.doesNotMatch(html, /temporarily unavailable/);
}));

test('a failed preview offers retry without inventing an empty list or a successful acknowledgement', async () => withHarness(async ({ h, gets, acks }) => {
    const goodGet = axios.get;
    axios.get = async (...args) => { gets.push(args); throw Error('Offline'); };
    h.open = true;
    let html = await h.render();
    assert.match(html, /Notifications could not load/);
    assert.doesNotMatch(html, /No notifications yet/);
    assert.equal(acks.length, 0);
    axios.get = goodGet;
    h.click('Try again');
    html = await h.render();
    assert.match(html, /Update first/);
    assert.doesNotMatch(html, /Notifications could not load/);
    assert.equal(acks.length, 1);
}));

test('a failed read acknowledgement retains loaded updates and the last confirmed unread count', async () => withHarness(async ({ h, acks }) => {
    const goodPatch = axios.patch;
    axios.patch = async (...args) => { acks.push(args); throw Error('Read storage unavailable'); };
    h.open = true;
    let html = await h.render();
    assert.match(html, /Update first/);
    assert.match(html, /could not mark them as read/);
    assert.match(html, /3 unread/);
    axios.patch = goodPatch;
    h.click('Try again');
    html = await h.render();
    assert.match(html, /2 unread/);
    assert.doesNotMatch(html, /could not mark them as read/);
}));

test('closing a pending preview cancels its work and late responses cannot mark unseen updates read', async () => withHarness(async ({ h, gets, acks }) => {
    const response = deferred();
    axios.get = (...args) => { gets.push(args); return response.promise; };
    h.open = true;
    assert.match(await h.render(), /Loading your updates/);
    h.close();
    await h.render();
    assert.equal(gets[0][1].signal.aborted, true);
    response.resolve({ data: { notices: [notice('late')], summary: { available: true, unread: 1 } } });
    await tick();
    assert.equal(acks.length, 0);
}));

test('reopening waits for newly rendered rows and does not acknowledge the previous preview again', async () => withHarness(async ({ h, acks }) => {
    h.open = true;
    await h.render();
    assert.equal(acks.length, 1);
    h.close();
    await h.render();
    const next = deferred();
    axios.get = () => next.promise;
    h.open = true;
    assert.match(await h.render(), /Loading your updates/);
    assert.equal(acks.length, 1);
    next.resolve({ data: { notices: [notice('newly-rendered')], summary: { available: true, unread: 3 } } });
    await tick();
    await h.render();
    assert.equal(acks.length, 2);
    assert.deepEqual(acks[1][1], { ids: ['newly-rendered'] });
}));

test('an account change cancels the old preview and cannot display or acknowledge its late response', async () => withHarness(async ({ h, acks }) => {
    const old = deferred();
    const signals = [];
    axios.get = (url, config) => { signals.push(config.signal); return signals.length === 1 ? old.promise : Promise.resolve({ data: { notices: [], summary: { available: true, unread: 0 } } }); };
    h.open = true;
    await h.render();
    let html = await h.render(ui.Menu, {}, { ...actor('seller'), id: 8 });
    assert.equal(signals[0].aborted, true);
    old.resolve({ data: { notices: [notice('old-account')], summary: { available: true, unread: 9 } } });
    await tick();
    html = await h.render(ui.Menu, {}, { ...actor('seller'), id: 8 });
    assert.doesNotMatch(html, /old-account/);
    assert.match(html, /No notifications yet/);
    assert.equal(acks.length, 0);
}));

test('opening a notice uses a focused Inertia action, rejects duplicate taps and closes only after success', async () => withHarness(async ({ h, visits }) => {
    h.open = true;
    await h.render();
    h.click('Update first');
    h.click('Update first');
    assert.equal(visits.length, 1);
    assert.equal(visits[0][0], '/notifications/first/read');
    assert.deepEqual(visits[0][1], { open: true });
    assert.equal(visits[0][2].preserveScroll, true);
    assert.equal(h.open, true);
    visits[0][2].onError(); visits[0][2].onFinish();
    assert.match(await h.render(), /could not be opened or marked as read/);
    assert.equal(h.open, true);
    h.click('Update first');
    visits[1][2].onSuccess(); visits[1][2].onFinish();
    assert.equal(h.open, false);
}));

for (const failure of ['expired', 'server', 'network']) test(`${failure} notice actions show a recoverable error without closing the preview`, async () => withHarness(async ({ h, listeners, visits }) => {
    h.open = true;
    await h.render();
    h.click('Update first');
    let prevented = false;
    const event = { detail: { response: { status: failure === 'expired' ? 419 : 503 } }, preventDefault: () => { prevented = true; } };
    listeners.get(failure === 'network' ? 'exception' : 'invalid')(event);
    const html = await h.render();
    assert.equal(prevented, true);
    assert.equal(h.open, true);
    assert.match(html, failure === 'expired' ? /session expired/ : failure === 'network' ? /connection/ : /could not be opened/);
    h.click('Update first');
    assert.equal(visits.length, 2);
}));

test('full-page mark-read and open controls use different owned actions and keep the current scroll', async () => withHarness(async ({ h, visits }) => {
    const props = { portal: 'account', accountUrl: '/pending-approval', available: true, filter: 'all', notices: page([notice('owned')]) };
    await h.render(ui.Notifications, props);
    h.click('Mark read');
    assert.equal(visits[0][0], '/notifications/owned/read');
    assert.deepEqual(visits[0][1], {});
    assert.equal(visits[0][2].preserveScroll, true);
    visits[0][2].onFinish();
    await h.render(ui.Notifications, props);
    h.click('View update');
    assert.deepEqual(visits[1][1], { open: true });
}));
