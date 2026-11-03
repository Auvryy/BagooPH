import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-seller-messages-'));
after(() => { rmSync(temporary, { recursive: true, force: true }); delete globalThis.sellerMessageHarness; });
const bundle = await build({
    entryPoints: [resolve(import.meta.dirname, '../../resources/js/Pages/Seller/Messages.tsx')],
    bundle: true, packages: 'external', platform: 'node', format: 'esm', jsx: 'automatic', write: false,
    plugins: [{ name: 'seller-message-interactions', setup(builder) {
        builder.onResolve({ filter: /^react$/ }, () => ({ path: 'hooks', namespace: 'isolated' }));
        builder.onResolve({ filter: /^@inertiajs\/react$/ }, () => ({ path: 'inertia', namespace: 'isolated' }));
        builder.onResolve({ filter: /hooks\/useMarketplaceComposer$/ }, () => ({ path: 'composer', namespace: 'isolated' }));
        builder.onResolve({ filter: /(?:Layouts\/DashboardLayout|Components\/ChatImageField|Components\/ChatAttachment)$/ }, () => ({ path: 'component', namespace: 'isolated' }));
        builder.onLoad({ filter: /.*/, namespace: 'isolated' }, ({ path }) => ({ contents: {
            hooks: `export const useState = value => globalThis.sellerMessageHarness.state(value); export const useRef = value => useState({ current: value })[0]; export const useEffect = () => {}; export default {};`,
            inertia: `export const Head = () => null; export const usePage = () => ({ props: { auth: { user: { id: 9, role: 'seller' } } } }); export const router = { reload() {} };`,
            composer: `export default context => ({ image: null, error: globalThis.sellerMessageHarness.error, sending: globalThis.sellerMessageHarness.sending, selectImage() {}, send: async input => { globalThis.sellerMessageHarness.sent.push(input); return globalThis.sellerMessageHarness.response; } });`,
            component: `export default function Component() {}`,
        }[path] }));
    } }],
});
const bundledPath = join(temporary, 'messages.mjs'); writeFileSync(bundledPath, bundle.outputFiles[0].text);
const { default: Messages } = await import(pathToFileURL(bundledPath).href);
const conversations = [
    { user: { id: 1, name: 'Alma Santos', role: 'buyer' }, last_message: 'Alma conversation', last_time: 'Today', unread_count: 1, messages: [] },
    { user: { id: 2, name: 'Berto Cruz', role: 'buyer' }, last_message: 'Berto conversation', last_time: 'Today', unread_count: 0, messages: [] },
];
const previousAnimationFrame = globalThis.requestAnimationFrame;
globalThis.requestAnimationFrame = callback => { callback(); return 0; };
after(() => { if (previousAnimationFrame) globalThis.requestAnimationFrame = previousAnimationFrame; else delete globalThis.requestAnimationFrame; });
function harness() {
    const slots = []; let cursor = 0;
    const h = {
        sent: [], response: undefined, error: '', sending: false,
        state(initial) { const i = cursor++; if (!(i in slots)) slots[i] = typeof initial === 'function' ? initial() : initial; return [slots[i], next => { slots[i] = typeof next === 'function' ? next(slots[i]) : next; }]; },
        render() { cursor = 0; return Messages({ conversations: structuredClone(conversations), shop: { id: 8 } }); },
    };
    globalThis.sellerMessageHarness = h;
    return h;
}
function nodes(tree, predicate) {
    if (!tree || typeof tree !== 'object') return [];
    if (Array.isArray(tree)) return tree.flatMap(child => nodes(child, predicate));
    return [...(predicate(tree) ? [tree] : []), ...nodes(tree.props?.children, predicate)];
}
function text(tree) {
    if (tree == null || typeof tree === 'boolean') return '';
    if (typeof tree !== 'object') return String(tree);
    return Array.isArray(tree) ? tree.map(text).join('') : text(tree.props?.children);
}
const contact = (tree, name) => nodes(tree, node => node.type === 'button' && text(node).includes(`${name} conversation`))[0];
const reply = tree => nodes(tree, node => node.type === 'input' && node.props.maxLength === 1000)[0];

test('seller drafts stay with their recipient when switching and returning to conversations', () => {
    const h = harness(); let tree = h.render();
    reply(tree).props.onChange({ target: { value: 'Draft for Alma only' } });
    tree = h.render(); contact(tree, 'Berto').props.onClick();
    tree = h.render(); assert.equal(reply(tree).props.value, '');
    reply(tree).props.onChange({ target: { value: 'Draft for Berto only' } });
    tree = h.render(); contact(tree, 'Alma').props.onClick();
    assert.equal(reply(h.render()).props.value, 'Draft for Alma only');
});

test('mobile conversation navigation has a labelled back action and keeps send controls named', () => {
    const h = harness(); let tree = h.render();
    const list = () => nodes(tree, node => node.props?.['aria-label'] === 'Conversation list')[0];
    const thread = () => nodes(tree, node => node.props?.['aria-label'] === 'Selected conversation')[0];
    assert.ok(list()); assert.ok(thread()); assert.match(thread().props.className, /hidden lg:flex/);
    contact(tree, 'Berto').props.onClick(); tree = h.render();
    assert.match(list().props.className, /hidden lg:flex/);
    const back = nodes(tree, node => node.type === 'button' && node.props['aria-label'] === 'Back to conversations')[0];
    assert.ok(back); back.props.onClick(); tree = h.render();
    assert.match(thread().props.className, /hidden lg:flex/);
    assert.equal(reply(tree).props['aria-label'], 'Message to Berto Cruz');
    assert.ok(nodes(tree, node => node.type === 'button' && node.props['aria-label'] === 'Send message').length);
});

test('failed sends retain the right draft and confirmed sends clear only that conversation', async () => {
    const h = harness(); let tree = h.render();
    reply(tree).props.onChange({ target: { value: 'Keep this after a failed send' } });
    tree = h.render(); await nodes(tree, node => node.type === 'form')[0].props.onSubmit({ preventDefault() {} });
    assert.equal(h.sent[0].receiver_id, 1); assert.equal(reply(h.render()).props.value, 'Keep this after a failed send');
    h.response = { id: 44, message: 'Sent', sender_id: 9, created_at: '2026-11-03T04:00:00Z' };
    tree = h.render(); await nodes(tree, node => node.type === 'form')[0].props.onSubmit({ preventDefault() {} });
    assert.equal(reply(h.render()).props.value, '');
    h.sending = true; tree = h.render();
    assert.equal(contact(tree, 'Berto').props.disabled, true);
    assert.ok(nodes(tree, node => node.type === 'button' && node.props['aria-label'] === 'Sending message' && node.props.disabled).length);
});

test('search ignores accidental surrounding spaces and missing city data stays neutral', () => {
    const h = harness(); let tree = h.render();
    const search = nodes(tree, node => node.type === 'input' && node.props.placeholder === 'Search messages...')[0];
    search.props.onChange({ target: { value: '  berto  ' } }); tree = h.render();
    assert.ok(contact(tree, 'Berto')); assert.equal(contact(tree, 'Alma'), undefined);
    assert.equal(text(tree).includes('Metro Manila'), false);
});
