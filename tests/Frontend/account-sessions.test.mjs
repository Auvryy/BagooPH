import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';

const temporary = mkdtempSync(resolve(import.meta.dirname, '../../.codex/account-session-ui-'));
after(() => { rmSync(temporary, { recursive: true, force: true }); delete globalThis.accountSessionHarness; delete globalThis.route; });
const bundled = await build({
    stdin: { contents: `export {default as Page} from '@/Pages/Account/Security'; export {default as Panel} from '@/Components/AccountSessionPanel'; export {default as Settings} from '@/Components/AccountSessionSettings'; export {default as Entry} from '@/Components/AccountSessionLink'; export * from '@/utils/accountSessions';`, resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
    bundle: true, packages: 'external', platform: 'node', format: 'esm', jsx: 'automatic', write: false,
    plugins: [{ name: 'account-session-interactions', setup(builder) {
        builder.onResolve({ filter: /^react$/ }, () => ({ path: 'hooks', namespace: 'security-hooks' }));
        builder.onLoad({ filter: /.*/, namespace: 'security-hooks' }, () => ({ contents: `export const useState = value => globalThis.accountSessionHarness.state(value); export const useEffect = (fn,deps) => globalThis.accountSessionHarness.effect(fn,deps); export const useRef = value => useState(() => ({current:value}))[0];` }));
        builder.onResolve({ filter: /^axios$/ }, () => ({ path: 'http', namespace: 'security-http' }));
        builder.onLoad({ filter: /.*/, namespace: 'security-http' }, () => ({ contents: `export default {post: (...args) => globalThis.accountSessionHarness.request(args), get: (...args) => globalThis.accountSessionHarness.request(args), isAxiosError: error => !!error?.response};` }));
        builder.onResolve({ filter: /^@inertiajs\/react$/ }, () => ({ path: 'inertia', namespace: 'security-inertia' }));
        builder.onLoad({ filter: /.*/, namespace: 'security-inertia' }, () => ({ contents: `export const Link='a', Head='head'; export const usePage=()=>({url:globalThis.accountSessionHarness.url,props:{auth:{user:globalThis.accountSessionHarness.user}}}); export const router={post:(...args)=>globalThis.accountSessionHarness.posts.push(args),reload:options=>globalThis.accountSessionHarness.reload(options)};` }));
        builder.onResolve({ filter: /^@headlessui\/react$/ }, () => ({ path: 'dialog', namespace: 'security-dialog' }));
        builder.onLoad({ filter: /.*/, namespace: 'security-dialog' }, () => ({ contents: `export const DialogTitle='h2';` }));
        builder.onResolve({ filter: /^lucide-react$/ }, () => ({ path: 'icons', namespace: 'security-icons' }));
        builder.onLoad({ filter: /.*/, namespace: 'security-icons' }, () => ({ contents: `export const ArrowLeft='svg',Check='svg',Clock3='svg',Globe='svg',History='svg',Laptop='svg',LogOut='svg',MonitorSmartphone='svg',RefreshCw='svg',ShieldCheck='svg',Smartphone='svg',Tablet='svg',ChevronRight='svg',ChevronDown='svg',Fingerprint='svg',KeyRound='svg',Mail='svg',UserRound='svg';` }));
        builder.onResolve({ filter: /(?:Layouts\/(?:BuyerOrderAccessLayout|DashboardLayout|CourierLayout|GuestLayout)|Components\/Modal)$/ }, args => ({ path: args.path.split('/').at(-1), namespace: 'security-layout' }));
        builder.onLoad({ filter: /.*/, namespace: 'security-layout' }, args => ({ contents: `export default ${JSON.stringify(args.path)};` }));
    } }],
});
const file = join(temporary, 'controls.mjs'); writeFileSync(file, bundled.outputFiles[0].text);
const ui = await import(pathToFileURL(file).href);
globalThis.route = name => '/' + name.replaceAll('.', '/');

function nodes(tree, predicate) {
    if (Array.isArray(tree)) return tree.flatMap(child => nodes(child, predicate));
    if (!tree || typeof tree !== 'object') return [];
    return [...(predicate(tree) ? [tree] : []), ...nodes(tree.props?.children, predicate)];
}
function text(tree) {
    if (Array.isArray(tree)) return tree.map(text).join(' ');
    if (tree === null || tree === undefined || typeof tree === 'boolean') return '';
    return typeof tree === 'object' ? text(tree.props?.children) : String(tree);
}
const button = (tree, label) => nodes(tree, node => node.type === 'button' && text(node).trim() === label)[0];
const change = (tree, type, value) => nodes(tree, node => node.props?.type === type)[0].props.onChange({ target: { value } });
const submit = tree => nodes(tree, node => node.type === 'form')[0].props.onSubmit({ preventDefault() {} });
const settle = () => new Promise(resolve => setImmediate(resolve));
const uuid = 'a28ca519-fc21-44c6-8de0-32b6c7c4c1ab';
const session = (id, current = false) => ({ id, channel: 'web', method: 'password', device: current ? 'Current browser' : 'Other browser', device_type: 'computer', network: null,
    signed_in_at: '2026-10-21T02:00:00Z', last_seen_at: '2026-10-21T02:05:00Z', ended_at: null, active: true, current, status: 'active' });
const page = data => ({ data, current_page: 1, last_page: 1, total: data.length, links: [] });
function harness({ role = 'buyer', canUsePortal = true, verified = true } = {}) {
    let cursor = 0, timerId = 0;
    const states = [], effects = [], timers = new Map();
    const previousWindow = globalThis.window;
    const frames = new Map(), events = new Map();
    globalThis.window = { location: { href: 'https://buyer.example.test/buyer/profile?tab=account', hash: '' }, addEventListener: (key, fn) => events.set(key, fn), removeEventListener: key => events.delete(key), requestAnimationFrame(fn) { const id = ++timerId; frames.set(id, fn); return id; }, cancelAnimationFrame: id => frames.delete(id), setTimeout(fn) { const id = ++timerId; timers.set(id, fn); return id; }, clearTimeout(id) { timers.delete(id); } };
    const h = {
        user: { id: 4, role }, url: '/buyer/profile?tab=account', requests: [], posts: [], reloads: 0, reloadFailure: false,
        props: { sessions: page([session('current', true), session(uuid)]), signIns: page([session('current', true)]), lastSignIn: session('current', true), currentSessionId: 'current', sessionOwnerId: 4, canConfirmByEmail: verified, canUsePortal, isOnline: true, historyDays: 90 },
        state(initial) {
            const index = cursor++;
            if (!(index in states)) states[index] = typeof initial === 'function' ? initial() : initial;
            return [states[index], value => { states[index] = typeof value === 'function' ? value(states[index]) : value; }];
        },
        effect(fn, deps) {
            const index = cursor++, old = effects[index];
            if (!old || JSON.stringify(old.deps) !== JSON.stringify(deps)) { old?.cleanup?.(); effects[index] = { deps, fn, run: true }; }
        },
        request(args) {
            let resolve, reject;
            const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
            h.requests.push({ args, resolve, reject }); return promise;
        },
        reload(options) { h.reloads++; if (!h.reloadFailure) options.onSuccess(); options.onFinish(); },
        render(component = ui.Panel, props = h.props) {
            globalThis.accountSessionHarness = h; cursor = 0;
            const tree = component(props);
            for (const effect of effects) if (effect?.run) { effect.run = false; effect.cleanup = effect.fn(); }
            return tree;
        },
        flush() { for (const [id, fn] of timers) { timers.delete(id); fn(); } },
        frame() { for (const [id, fn] of frames) { frames.delete(id); fn(); } },
        event(name) { events.get(name)?.(); },
        close() { for (const effect of effects) effect?.cleanup?.(); globalThis.window = previousWindow; delete globalThis.accountSessionHarness; },
    };
    h.props.onRefresh = () => new Promise((resolve, reject) => h.reload({ onSuccess: resolve, onFinish: () => { if (h.reloadFailure) reject(new Error('Refresh failed')); } }));
    globalThis.accountSessionHarness = h; return h;
}

test('all roles use their portal shell; restricted accounts keep only their narrow security surface', () => {
    for (const [role, layout] of [['buyer', 'BuyerOrderAccessLayout'], ['seller', 'DashboardLayout'], ['courier', 'CourierLayout'], ['logistics', 'DashboardLayout'], ['admin', 'DashboardLayout']]) {
        const h = harness({ role });
        try { assert.equal(h.render(ui.Page).type, layout); const tree = h.render(); assert.match(text(tree), /Exact location is not tracked/); assert.match(text(tree), /same device/); } finally { h.close(); }
    }
    const h = harness({ role: 'courier', canUsePortal: false });
    try { assert.equal(h.render(ui.Page).type, 'GuestLayout'); } finally { h.close(); }
});

test('missing authentication never renders stale account sessions', () => {
    const h = harness();
    try { h.user = null; const tree = h.render(); assert.match(text(tree), /Sign in to review/); assert.doesNotMatch(text(tree), /Other browser/); } finally { h.close(); }
});

test('legacy and empty histories explain unknown dates without inventing activity or location', () => {
    const h = harness();
    try {
        h.props.lastSignIn = null; h.props.signIns = page([]); h.props.sessions.data[1].signed_in_at = null;
        const tree = h.render(); assert.match(text(tree), /Not recorded/); assert.match(text(tree), /before sign-in history was recorded/); assert.match(text(tree), /No recorded sign-ins/);
        assert.equal(ui.sessionDate(null), 'Not recorded'); assert.equal(ui.sessionDate('bad'), 'Not recorded'); assert.match(ui.sessionDate('2026-10-21T02:00:00Z'), /10:00/);
    } finally { h.close(); }
});

test('current sign-out uses the existing logout action and remote selection requires confirmation', () => {
    const h = harness();
    try {
        let tree = h.render(); nodes(tree, n => n.props?.['aria-label'] === 'Sign out this session')[0].props.onClick(); assert.equal(h.posts[0][0], '/logout');
        nodes(tree, n => n.props?.['aria-label'] === 'Sign out Other browser')[0].props.onClick(); tree = h.render();
        assert.match(text(tree), /Other browser will need to sign in again/); assert.equal(h.requests.length, 0);
        submit(tree); assert.equal(h.requests.length, 0); assert.match(text(h.render()), /Enter your current password/);
    } finally { h.close(); }
});

test('double submits share one intent and an interrupted retry cannot expand its scope', async () => {
    const h = harness();
    try {
        button(h.render(), 'Sign out others').props.onClick(); change(h.render(), 'password', 'Password1234');
        let tree = h.render(); submit(tree); submit(tree); assert.equal(h.requests.length, 1);
        const first = h.requests[0]; assert.equal(first.args[0], '/account/security/sessions/revoke-others'); assert.match(first.args[1].request_token, /^[0-9a-f-]{36}$/i);
        assert.equal(first.args[1].current_password, 'Password1234'); assert.equal(first.args[2].headers.Accept, 'application/json');
        first.reject({ response: { status: 419 } }); await settle(); assert.match(text(h.render()), /session expired/);
        submit(h.render()); assert.equal(h.requests.length, 2); assert.equal(h.requests[1].args[1].request_token, first.args[1].request_token);
        h.requests[1].resolve({ data: { reference: uuid, affected_count: 1, message: 'The other session was signed out.' } }); await settle();
        assert.equal(h.reloads, 1); assert.match(text(h.render()), /The other session was signed out/);
    } finally { h.close(); }
});

test('malformed responses never claim saved success and retain the same retry intent', async () => {
    const h = harness();
    try {
        button(h.render(), 'Sign out others').props.onClick(); change(h.render(), 'password', 'Password1234'); submit(h.render());
        const key = h.requests[0].args[1].request_token; h.requests[0].resolve({ data: '<html>Sign in</html>' }); await settle();
        assert.match(text(h.render()), /could not be confirmed/); assert.equal(h.reloads, 0); submit(h.render()); assert.equal(h.requests[1].args[1].request_token, key);
    } finally { h.close(); }
    for (const data of [null, [], { reference: '-'.repeat(36), affected_count: 1, message: 'Saved' }, { reference: uuid, affected_count: -1, message: 'Saved' }, { reference: uuid, affected_count: 1, message: '' }]) assert.throws(() => ui.confirmedSessionResult(data));
});

test('verified email sends no client-selected identity, observes cooldown and validates six digits', async () => {
    const h = harness();
    try {
        button(h.render(), 'Sign out others').props.onClick(); let tree = h.render(); nodes(tree, n => n.props?.type === 'radio' && n.props.value === 'email')[0].props.onChange();
        tree = h.render(); button(tree, 'Send code').props.onClick(); assert.deepEqual(h.requests[0].args.slice(0, 2), ['/account/security/code', {}]);
        h.requests[0].resolve({ data: { success: true, cooldown: 60, expires_in: 600 } }); await settle(); tree = h.render();
        assert.equal(button(tree, 'Resend in 60s').props.disabled, true); assert.match(text(tree), /Code sent/);
        change(tree, 'text', '１２３４５６'); submit(h.render()); assert.equal(h.requests.length, 1); assert.match(text(h.render()), /six-digit code/);
        change(h.render(), 'text', '123456'); submit(h.render()); assert.equal(h.requests[1].args[1].code, '123456'); assert.equal(h.requests[1].args[1].current_password, null);
    } finally { h.close(); }
});

test('unverified accounts cannot select email and failed delivery never displays code sent', async () => {
    let h = harness({ verified: false });
    try { button(h.render(), 'Sign out others').props.onClick(); assert.equal(nodes(h.render(), n => n.props?.type === 'radio').length, 0); } finally { h.close(); }
    h = harness();
    try {
        button(h.render(), 'Sign out others').props.onClick(); nodes(h.render(), n => n.props?.value === 'email')[0].props.onChange(); button(h.render(), 'Send code').props.onClick();
        h.requests[0].reject({ response: { status: 503, data: { message: 'We could not send the verification code.' } } }); await settle();
        const tree = h.render(); assert.match(text(tree), /could not send/); assert.doesNotMatch(text(tree), /Code sent/);
    } finally { h.close(); }
});

test('owner changes abort pending work and old responses cannot populate the next account', async () => {
    const h = harness();
    try {
        button(h.render(), 'Sign out others').props.onClick(); change(h.render(), 'password', 'Password1234'); submit(h.render());
        h.user = { id: 8, role: 'seller' }; h.render(); h.render(); assert.equal(h.requests[0].args[2].signal.aborted, true);
        assert.doesNotMatch(text(h.render()), /Other browser/); assert.match(text(h.render()), /Reload login activity/);
        h.requests[0].resolve({ data: { reference: uuid, affected_count: 1, message: 'Old account result' } }); await settle();
        assert.doesNotMatch(text(h.render()), /Old account result/); assert.equal(h.reloads, 0);
    } finally { h.close(); }
});

test('leaving a page aborts its confirmation request', () => {
    const h = harness(); button(h.render(), 'Sign out others').props.onClick(); change(h.render(), 'password', 'Password1234'); submit(h.render());
    const signal = h.requests[0].args[2].signal; h.close(); assert.equal(signal.aborted, true);
});

test('saved results stay truthful when refreshing the current view fails', async () => {
    const h = harness();
    try {
        h.reloadFailure = true; button(h.render(), 'Sign out others').props.onClick(); change(h.render(), 'password', 'Password1234'); submit(h.render());
        h.requests[0].resolve({ data: { reference: uuid, affected_count: 1, message: 'The other session was signed out.' } }); await settle();
        const tree = h.render(); assert.match(text(tree), /The other session was signed out/); assert.match(text(tree), /updated list could not load/);
    } finally { h.close(); }
});

test('each settings entry uses the shared private route and rider corners stay local', () => {
    for (const theme of ['default', 'buyer', 'seller', 'courier']) {
        const tree = ui.Entry({ theme }); assert.equal(nodes(tree, n => n.type === 'a')[0].props.href, '/account/security');
        if (theme === 'courier') assert.match(tree.props.className, /rounded-\[8px\]/);
    }
});

test('request identifiers use secure browser randomness including the UUID fallback', () => {
    assert.match(ui.sessionRequestToken(), /^[0-9a-f-]{36}$/i);
    const previous = Object.getOwnPropertyDescriptor(globalThis, 'crypto');
    try {
        Object.defineProperty(globalThis, 'crypto', { configurable: true, value: { getRandomValues(bytes) { bytes.fill(3); return bytes; } } });
        assert.match(ui.sessionRequestToken(), /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
        Object.defineProperty(globalThis, 'crypto', { configurable: true, value: {} }); assert.throws(() => ui.sessionRequestToken(), /securely prepare/);
    } finally { if (previous) Object.defineProperty(globalThis, 'crypto', previous); else delete globalThis.crypto; }
});
