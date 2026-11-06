import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';

const root = resolve(import.meta.dirname, '../..');
const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-buyer-messages-'));
after(() => {
    rmSync(temporary, { recursive: true, force: true });
    delete globalThis.buyerMessagesHarness;
});

const bundle = await build({
    entryPoints: [resolve(root, 'resources/js/Pages/Buyer/Messages.tsx')],
    bundle: true, packages: 'external', platform: 'node', format: 'esm', jsx: 'automatic', write: false,
    plugins: [{ name: 'buyer-message-render-harness', setup(builder) {
        builder.onResolve({ filter: /^react$/ }, () => ({ path: 'react-hooks', namespace: 'buyer-messages' }));
        builder.onLoad({ filter: /^react-hooks$/, namespace: 'buyer-messages' }, () => ({ contents: `
            export const useState = initial => globalThis.buyerMessagesHarness.state(initial);
            export const useRef = initial => globalThis.buyerMessagesHarness.ref(initial);
            export const useEffect = effect => globalThis.buyerMessagesHarness.effect(effect);
            export default {};
        ` }));
        builder.onResolve({ filter: /^@inertiajs\/react$/ }, () => ({ path: 'inertia', namespace: 'buyer-messages' }));
        builder.onLoad({ filter: /^inertia$/, namespace: 'buyer-messages' }, () => ({ contents: `
            export const Head = () => null;
            export const router = {};
            export const usePage = () => ({ props: { auth: { user: { id: 9, role: 'buyer' } } } });
        ` }));
        builder.onResolve({ filter: /hooks\/useMarketplaceComposer$/ }, () => ({ path: 'composer', namespace: 'buyer-messages' }));
        builder.onLoad({ filter: /^composer$/, namespace: 'buyer-messages' }, () => ({ contents: `
            export default () => ({ image: null, error: '', sending: false, selectImage() {}, send: async () => null });
        ` }));
        builder.onResolve({ filter: /(?:Layouts\/BuyerLayout|Components\/ChatImageField|Components\/ChatAttachment)$/ }, () => ({ path: 'component', namespace: 'buyer-messages' }));
        builder.onLoad({ filter: /^component$/, namespace: 'buyer-messages' }, () => ({ contents: `
            export default function Component({ children }) { return children ?? null; }
        ` }));
    } }],
});

const modulePath = join(temporary, 'buyer-messages.mjs');
writeFileSync(modulePath, bundle.outputFiles[0].text);
const { default: BuyerMessages } = await import(pathToFileURL(modulePath).href);

function harness() {
    const slots = [];
    let cursor = 0;
    let effectIndex = 0;
    const value = {
        state(initial) {
            const index = cursor++;
            if (!(index in slots)) slots[index] = typeof initial === 'function' ? initial() : initial;
            return [slots[index], next => { slots[index] = typeof next === 'function' ? next(slots[index]) : next; }];
        },
        ref(initial) { return this.state({ current: initial })[0]; },
        effect(effect) {
            effectIndex += 1;
            // Execute only the selection-sync effect; network history and scroll effects are outside this render test.
            if (effectIndex === 2) effect();
        },
        render(conversations) {
            cursor = 0;
            effectIndex = 0;
            return BuyerMessages({ conversations: structuredClone(conversations) });
        },
    };
    globalThis.buyerMessagesHarness = value;
    return value;
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

const conversations = [
    { user: { id: 101, name: 'Alma Santos', role: 'seller', shop: { id: 11, name: 'Alma Shop' } }, last_message: 'Alma preview', last_time: 'Today', unread_count: 2,
        messages: [{ id: 1, sender_id: 101, message: 'Alma thread', created_at: '2026-11-06T08:00:00Z' }] },
    { user: { id: 202, name: 'Berto Cruz', role: 'seller', shop: { id: 22, name: 'Berto Shop', logo_url: '/images/berto.webp' } }, last_message: 'Berto preview', last_time: 'Yesterday', unread_count: 3,
        messages: [{ id: 2, sender_id: 202, message: 'Berto thread', created_at: '2026-11-05T08:00:00Z' }] },
];

test('filtering keeps the selected conversation by user ID when the full list returns', () => {
    const h = harness();
    let tree = h.render(conversations);
    assert.match(text(tree), /2 conversations/);
    assert.match(text(tree), /5 unread/);

    let search = nodes(tree, node => node.type === 'input' && node.props.placeholder === 'Search chats, merchants, riders...')[0];
    search.props.onChange({ target: { value: 'berto' } });
    tree = h.render(conversations);
    assert.match(text(tree), /Berto Shop/);
    assert.doesNotMatch(text(tree), /Alma Shop/);

    search = nodes(tree, node => node.type === 'input' && node.props.placeholder === 'Search chats, merchants, riders...')[0];
    search.props.onChange({ target: { value: '' } });
    tree = h.render(conversations);
    const activeHeading = nodes(tree, node => node.type === 'h3' && text(node).includes('Berto Shop'))[0];
    assert.ok(activeHeading, 'the previously selected Berto conversation stays active after clearing the filter');
});

test('empty and unmatched searches show useful empty states and truthful unread counts', () => {
    const h = harness();
    let tree = h.render([]);
    assert.match(text(tree), /0 conversations/);
    assert.doesNotMatch(text(tree), /\d+ unread/);
    assert.match(text(tree), /No conversations found/);
    assert.match(text(tree), /No matching conversation/);

    tree = h.render(conversations);
    const search = nodes(tree, node => node.type === 'input' && node.props.placeholder === 'Search chats, merchants, riders...')[0];
    search.props.onChange({ target: { value: 'no matching person' } });
    tree = h.render(conversations);
    assert.match(text(tree), /No conversations found/);
    assert.match(text(tree), /Clear search/);
});

test('mobile back and contact controls switch between the conversation list and active thread', () => {
    const h = harness();
    let tree = h.render(conversations);
    const panels = () => {
        const list = nodes(tree, node => node.type === 'div' && /w-full md:w-80 lg:w-96/.test(node.props.className ?? ''))[0];
        const thread = nodes(tree, node => node.type === 'div' && /flex-1 flex flex-col h-full/.test(node.props.className ?? ''))[0];
        return { list, thread };
    };
    assert.match(panels().list.props.className, /hidden md:flex/);
    assert.match(panels().thread.props.className, /flex/);

    const back = nodes(tree, node => node.type === 'button' && node.props.title === 'Back to conversations')[0];
    assert.ok(back);
    assert.match(back.props.className, /md:hidden/);
    back.props.onClick();
    tree = h.render(conversations);
    assert.match(panels().list.props.className, /flex/);
    assert.match(panels().thread.props.className, /hidden md:flex/);

    const berto = nodes(tree, node => node.type === 'button' && text(node).includes('Berto preview'))[0];
    assert.ok(berto);
    berto.props.onClick();
    tree = h.render(conversations);
    assert.match(panels().list.props.className, /hidden md:flex/);
    assert.doesNotMatch(panels().thread.props.className, /hidden/);
    assert.ok(nodes(tree, node => node.type === 'h3' && text(node).includes('Berto Shop')).length);
});

test('message threads preserve the product preview, attached image and bottom composer shown in the report', () => {
    const messages = readFileSync(resolve(root, 'resources/js/Pages/Buyer/Messages.tsx'), 'utf8');
    const attachment = readFileSync(resolve(root, 'resources/js/Components/ChatAttachment.tsx'), 'utf8');
    assert.match(messages, /Product Reference/);
    assert.match(messages, /src=\{msg\.product\.featured_image \|\| ''\}/);
    assert.match(messages, /PHP \{Number\(msg\.product\.price\)\.toFixed\(2\)\}/);
    assert.match(messages, /<ChatAttachment url=\{msg\.attachment_url\} \/>/);
    assert.match(attachment, /<img src=\{url\} alt="Photo attached to this message"/);
    assert.match(attachment, /max-h-56 max-w-full rounded-lg object-contain/);
    assert.match(messages, /<form onSubmit=\{handleSendMessage\} className="[^"]*shrink-0/);
});

test('product chat modal keeps only its message history scrollable', () => {
    const source = readFileSync(resolve(root, 'resources/js/Components/ChatModal.tsx'), 'utf8');
    assert.match(source, /<Dialog open=\{isOpen\}/);
    assert.match(source, /DialogPanel className="[^"]*overflow-hidden/);
    assert.match(source, /<div className="flex-1 p-4 overflow-y-auto space-y-3 bg-\[#F8FAFC\]">/);
    assert.match(source, /<form onSubmit=\{handleSend\} className="[^"]*shrink-0/);
});
