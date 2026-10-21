import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';

const temporary = mkdtempSync(resolve(import.meta.dirname, '../../.codex/account-session-ui-'));
after(() => { rmSync(temporary, { recursive: true, force: true }); delete globalThis.accountSessionHarness; delete globalThis.route; });
const bundled = await build({
    stdin: { contents: `export {default as Page} from '@/Pages/Account/Security'; export {default as Panel} from '@/Components/AccountSessionPanel'; export {default as Settings} from '@/Components/AccountSessionSettings'; export {default as Navigation} from '@/Components/BuyerAccountNavigation'; export {default as useSections} from '@/hooks/useSettingsSections'; export {default as Entry} from '@/Components/AccountSessionLink'; export * from '@/utils/accountSessions'; export * from '@/utils/accountSections';`, resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
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

test('account navigation reveals real section links only while Account & Security is active', () => {
    const selected = [];
    let tree = ui.Navigation({ active: false, selected: 'account-personal', disabled: false, onSelect: id => selected.push(id) });
    assert.equal(nodes(tree, n => n.props?.id === 'buyer-account-submenu')[0].props.hidden, true);
    const parent = nodes(tree, n => n.type === 'button')[0];
    assert.equal(parent.props['aria-expanded'], false); parent.props.onClick(); assert.deepEqual(selected, ['account-personal']);
    tree = ui.Navigation({ active: true, selected: 'login-activity', disabled: false, onSelect: id => selected.push(id) });
    const submenu = nodes(tree, n => n.props?.id === 'buyer-account-submenu')[0];
    assert.equal(submenu.props.hidden, false); assert.match(submenu.props.className, /border-l/);
    const links = nodes(tree, n => n.type === 'a');
    assert.equal(links.length, 6); assert.equal(links.at(-1).props['aria-current'], 'location');
    let prevented = false;
    links.at(-1).props.onClick({ preventDefault() { prevented = true; } });
    assert(prevented); assert.equal(selected.at(-1), 'login-activity');
    prevented = false; links[0].props.onClick({ ctrlKey: true, preventDefault() { prevented = true; } }); assert.equal(prevented, false);
    tree = ui.Navigation({ active: true, selected: 'account-personal', disabled: true, onSelect: id => selected.push(id) });
    const count = selected.length; nodes(tree, n => n.type === 'a')[0].props.onClick({ preventDefault() {} }); assert.equal(selected.length, count);
});

test('every normal portal embeds its owned session list without navigating to a new page', async () => {
    for (const role of ['buyer', 'seller', 'courier', 'logistics', 'admin']) {
        const h = harness({ role });
        try {
            let tree = h.render(ui.Settings, {});
            assert.match(text(tree), /Loading your signed-in sessions/); assert.equal(tree.props.id, 'login-activity');
            assert.equal(h.requests.length, 1); assert.equal(h.requests[0].args[0], '/account/security');
            assert.equal(h.requests[0].args[1].headers.Accept, 'application/json');
            h.requests[0].resolve({ data: h.props }); await settle(); tree = h.render(ui.Settings, {});
            const panel = nodes(tree, n => n.type === ui.Panel)[0]; assert(panel); assert.equal(panel.props.sessionOwnerId, h.user.id);
            assert.equal(nodes(tree, n => n.type === 'a').length, 0); assert.equal(h.posts.length, 0); assert.equal(h.reloads, 0);
        } finally { h.close(); }
    }
});

test('hidden privacy sections do not request login activity and activation loads it once', async () => {
    const h = harness({ role: 'courier' });
    try {
        h.render(ui.Settings, { enabled: false }); assert.equal(h.requests.length, 0);
        h.render(ui.Settings, { enabled: true }); assert.equal(h.requests.length, 1);
        h.render(ui.Settings, { enabled: true }); assert.equal(h.requests.length, 1);
        const signal = h.requests[0].args[1].signal;
        h.render(ui.Settings, { enabled: false }); assert.equal(signal.aborted, true);
        h.requests[0].resolve({ data: h.props }); await settle(); assert.equal(nodes(h.render(ui.Settings, { enabled: false }), n => n.type === ui.Panel).length, 0);
        h.render(ui.Settings, { enabled: true }); assert.equal(h.requests.length, 2);
    } finally { h.close(); }
});

test('private-list failures offer retry and HTML or another owner is never rendered', async () => {
    for (const response of ['<html>Sign in</html>', { sessionOwnerId: 99 }, { ...harnessData(), sessions: { data: [] } }]) {
        const h = harness();
        try {
            h.render(ui.Settings, {}); h.requests[0].resolve({ data: response }); await settle();
            let tree = h.render(ui.Settings, {}); assert.match(text(tree), /Login activity could not load/); assert.equal(nodes(tree, n => n.type === ui.Panel).length, 0);
            button(tree, 'Try again').props.onClick(); h.requests[1].resolve({ data: h.props }); await settle();
            tree = h.render(ui.Settings, {}); assert.equal(nodes(tree, n => n.type === ui.Panel).length, 1);
        } finally { h.close(); }
    }
    const h = harness();
    try {
        h.render(ui.Settings, {}); h.requests[0].reject({ response: { status: 401 } }); await settle();
        assert.match(text(h.render(ui.Settings, {})), /session has ended. Sign in again/);
    } finally { h.close(); }
});

function harnessData() {
    return { sessions: page([session('current', true)]), signIns: page([]), lastSignIn: null, currentSessionId: 'current', sessionOwnerId: 4, canConfirmByEmail: true, historyDays: 90 };
}

test('changing account immediately hides old sessions, aborts the request and ignores its late response', async () => {
    const h = harness();
    try {
        h.render(ui.Settings, {}); const first = h.requests[0];
        h.user = { id: 8, role: 'seller' }; let tree = h.render(ui.Settings, {});
        assert.equal(nodes(tree, n => n.type === ui.Panel).length, 0); assert.equal(first.args[1].signal.aborted, true);
        first.resolve({ data: h.props }); await settle(); assert.equal(nodes(h.render(ui.Settings, {}), n => n.type === ui.Panel).length, 0);
        h.requests[1].resolve({ data: { ...h.props, sessionOwnerId: 8 } }); await settle();
        tree = h.render(ui.Settings, {}); assert.equal(nodes(tree, n => n.type === ui.Panel)[0].props.sessionOwnerId, 8);
    } finally { h.close(); }
});

test('embedded pagination and refresh retain both cursors without leaving account settings', async () => {
    const h = harness();
    try {
        h.render(ui.Settings, {}); h.requests[0].resolve({ data: h.props }); await settle();
        let panel = nodes(h.render(ui.Settings, {}), n => n.type === ui.Panel)[0];
        const next = panel.props.onPageChange('/account/security?sessions_page=2&history_page=3&user_id=99');
        assert.equal(h.requests[1].args[0], '/account/security?sessions_page=2&history_page=3');
        h.requests[1].resolve({ data: { ...h.props, sessions: { ...h.props.sessions, current_page: 2, last_page: 2 }, signIns: { ...h.props.signIns, current_page: 3, last_page: 3 } } }); await next;
        panel = nodes(h.render(ui.Settings, {}), n => n.type === ui.Panel)[0]; assert.equal(panel.props.sessions.current_page, 2); assert.equal(panel.props.signIns.current_page, 3);
        const refresh = panel.props.onRefresh(); assert.equal(h.requests[2].args[0], '/account/security?sessions_page=2&history_page=3');
        h.requests[2].resolve({ data: h.props }); await refresh;
        assert.equal(h.posts.length, 0); assert.equal(h.reloads, 0);
    } finally { h.close(); }
});

test('newer list requests win and unmounting aborts private reads', async () => {
    const h = harness();
    try {
        h.render(ui.Settings, {}); h.requests[0].resolve({ data: h.props }); await settle();
        const panel = nodes(h.render(ui.Settings, {}), n => n.type === ui.Panel)[0];
        const older = panel.props.onPageChange('/account/security?sessions_page=2');
        const newer = panel.props.onPageChange('/account/security?sessions_page=3');
        assert.equal(h.requests[1].args[1].signal.aborted, true);
        h.requests[2].resolve({ data: { ...h.props, sessions: { ...h.props.sessions, current_page: 3, last_page: 3 } } }); await newer;
        h.requests[1].resolve({ data: h.props }); await older;
        assert.equal(nodes(h.render(ui.Settings, {}), n => n.type === ui.Panel)[0].props.sessions.current_page, 3);
        const last = panel.props.onRefresh(); const signal = h.requests[3].args[1].signal;
        h.close(); assert.equal(signal.aborted, true); h.requests[3].resolve({ data: h.props }); await last;
    } finally { if (globalThis.accountSessionHarness === h) h.close(); }
});

test('embedded page buttons use the focused loader and disable while a page is loading', async () => {
    const h = harness(); let resolve;
    try {
        h.props.sessions = { ...h.props.sessions, last_page: 2, links: [{ url: null, label: 'Previous', active: false }, { url: '/account/security?sessions_page=1', label: '1', active: true }, { url: '/account/security?sessions_page=2', label: '2', active: false }, { url: '/account/security?sessions_page=2', label: 'Next', active: false }] };
        const selected = []; h.props.onPageChange = url => { selected.push(url); return new Promise(yes => { resolve = yes; }); };
        const pagination = () => { const element = nodes(h.render(), n => typeof n.type === 'function' && n.type.name === 'SessionPagination')[0]; return element.type(element.props); };
        let tree = pagination(); assert.equal(nodes(tree, n => n.type === 'a').length, 0); button(tree, 'Next').props.onClick();
        assert.deepEqual(selected, ['/account/security?sessions_page=2']); tree = pagination(); assert.equal(button(tree, 'Next').props.disabled, true);
        resolve(); await settle(); assert.equal(button(pagination(), 'Next').props.disabled, false);
    } finally { h.close(); }
});

test('only bounded relative session pages and well-formed owned data are accepted', () => {
    assert.equal(ui.sessionListUrl('/account/security?history_page=2&role=admin'), '/account/security?history_page=2');
    for (const url of ['https://other.example.test/account/security', '//other.example.test/account/security', '/logout', '/account/security?sessions_page=0', '/account/security?history_page=1000001', '/account/security?sessions_page=NaN']) assert.throws(() => ui.sessionListUrl(url));
    const data = harnessData(); assert.equal(ui.confirmedSessionPage(data, 4), data);
    for (const malformed of [null, [], 'html', { ...data, sessionOwnerId: 8 }, { ...data, sessions: { ...data.sessions, data: [{ ...session('current'), device: {} }] } }, { ...data, signIns: { ...data.signIns, total: -1 } }]) assert.throws(() => ui.confirmedSessionPage(malformed, 4));
});

function scrollFixture() {
    const priorDocument = globalThis.document, calls = [], target = { getBoundingClientRect: () => ({ top: 350 }), focus: options => calls.push(['focus', options]), scrollIntoView: options => calls.push(['document', options]) };
    const container = { scrollHeight: 1300, clientHeight: 600, scrollTop: 50, contains: element => element === target, getBoundingClientRect: () => ({ top: 100 }), scrollTo: options => calls.push(['container', options]) };
    globalThis.document = { getElementById: id => ['account-personal', 'login-activity'].includes(id) ? target : null };
    let reduced = false;
    window.matchMedia = () => ({ matches: reduced }); window.getComputedStyle = () => ({ overflowY: 'auto' });
    return { container, calls, reduced: value => { reduced = value; }, close: () => { globalThis.document = priorDocument; } };
}

test('section scrolling uses the desktop pane, mobile document and reduced motion correctly', () => {
    const h = harness(), fixture = scrollFixture();
    try {
        assert.equal(ui.scrollSettingsSection('login-activity', fixture.container), true);
        assert.deepEqual(fixture.calls[0], ['container', { top: 288, behavior: 'smooth' }]); assert.equal(fixture.calls.filter(call => call[0] === 'document').length, 0);
        assert.deepEqual(fixture.calls[1], ['focus', { preventScroll: true }]);
        fixture.calls.length = 0; fixture.container.clientHeight = 1400; fixture.reduced(true);
        ui.scrollSettingsSection('login-activity', fixture.container); assert.deepEqual(fixture.calls[0], ['document', { block: 'start', behavior: 'auto' }]);
        assert.equal(ui.scrollSettingsSection('missing', fixture.container), false);
        fixture.container.contains = () => false; assert.equal(ui.scrollSettingsSection('account-personal', fixture.container), false);
    } finally { fixture.close(); h.close(); }
});

test('a section selected from another tab waits for the account panel before scrolling', () => {
    const h = harness(), fixture = scrollFixture(), root = { current: fixture.container };
    let active = false;
    const render = () => h.render(() => ui.useSections(active, ['account-personal', 'login-activity'], root), {});
    try {
        render().select('login-activity'); render(); h.frame(); assert.equal(fixture.calls.length, 0);
        active = true; render(); h.frame(); assert.equal(fixture.calls.filter(call => call[0] === 'container').length, 1);
        assert.equal(render().selected, 'login-activity');
        render().select('account-personal'); render(); h.frame(); assert.equal(render().selected, 'account-personal');
    } finally { fixture.close(); h.close(); }
});

test('initial account anchors and hash navigation scroll after rendering and clean up on leave', () => {
    const h = harness(), fixture = scrollFixture(), root = { current: fixture.container };
    const render = () => h.render(() => ui.useSections(true, ['account-personal', 'login-activity'], root), {});
    try {
        window.location.href += '#login-activity'; render(); render(); h.frame(); assert.equal(render().selected, 'login-activity');
        assert.equal(fixture.calls.filter(call => call[0] === 'container').length, 1);
        window.location.href = 'https://buyer.example.test/buyer/profile?tab=account#account-personal'; h.event('hashchange'); render(); h.frame(); assert.equal(render().selected, 'account-personal');
        render().select('login-activity'); render(); const count = fixture.calls.length; h.close(); h.frame(); assert.equal(fixture.calls.length, count);
        assert.equal(ui.settingsSectionFromUrl('/buyer/profile#unknown', ['login-activity']), null);
        assert.equal(ui.settingsSectionFromUrl('/buyer/profile#%broken', ['login-activity']), null);
    } finally { fixture.close(); if (globalThis.accountSessionHarness === h) h.close(); }
});

test('account shortcuts target existing sections and all normal settings reuse the embedded controls', () => {
    const source = readFileSync(resolve(import.meta.dirname, '../../resources/js/Pages/Buyer/Profile.tsx'), 'utf8');
    for (const { id } of ui.buyerAccountSections) {
        if (id === 'login-activity') continue;
        assert(source.includes(`id="${id}"`), `Missing settings destination: ${id}`);
    }
    for (const file of ['Buyer/Profile', 'Seller/Profile', 'Courier/Profile', 'Profile/Edit']) {
        const code = readFileSync(resolve(import.meta.dirname, `../../resources/js/Pages/${file}.tsx`), 'utf8');
        assert.match(code, /<AccountSessionSettings/); assert.doesNotMatch(code, /<AccountSessionLink/);
    }
});
