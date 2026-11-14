import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createInertiaApp } from '@inertiajs/react';

const root = resolve(import.meta.dirname, '../..');
const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-transport-loss-'));
after(() => {
    rmSync(temporary, { recursive: true, force: true });
    delete globalThis.lossHarness;
});

const target = /Components\/Logistics\/TransportLoss\.tsx$/;
const bundle = await build({
    stdin: { contents: "export {default} from '@/Components/Logistics/TransportLoss';", resolveDir: root, loader: 'tsx' },
    bundle: true,
    platform: 'node',
    format: 'esm',
    packages: 'external',
    jsx: 'automatic',
    write: false,
    loader: { '.css': 'empty' },
    plugins: [{
        name: 'transport-loss-harness',
        setup(builder) {
            builder.onResolve({ filter: /^@inertiajs\/react$/ }, args => target.test(args.importer) ? { path: 'inertia', namespace: 'wtl-probe' } : undefined);
            builder.onLoad({ filter: /^inertia$/, namespace: 'wtl-probe' }, () => ({
                contents: "export * from '@inertiajs/react'; export const useForm = initial => globalThis.lossHarness.useForm(initial); export const usePage = () => ({ props: globalThis.lossHarness.pageProps });",
                resolveDir: root,
            }));
            builder.onResolve({ filter: /^react$/ }, args => target.test(args.importer) ? { path: 'react', namespace: 'wtl-probe' } : undefined);
            builder.onLoad({ filter: /^react$/, namespace: 'wtl-probe' }, () => ({
                contents: "export * from 'react'; export const useRef = initial => globalThis.lossHarness.ref(initial); export const useState = initial => [globalThis.lossHarness.state(initial), () => {}]; export const useCallback = fn => fn;",
                resolveDir: root,
            }));
            builder.onResolve({ filter: /^@\/hooks\/useCourierRequestError$/ }, args => target.test(args.importer) ? { path: 'hook', namespace: 'wtl-probe' } : undefined);
            builder.onLoad({ filter: /^hook$/, namespace: 'wtl-probe' }, () => ({
                contents: 'export default function useRequestError() {}',
                resolveDir: root,
            }));
            builder.onResolve({ filter: /^react\/jsx-runtime$/ }, args => target.test(args.importer) ? { path: 'jsx', namespace: 'wtl-probe' } : undefined);
            builder.onLoad({ filter: /^jsx$/, namespace: 'wtl-probe' }, () => ({
                contents: "import { jsx as one, jsxs as many, Fragment } from 'react/jsx-runtime'; export { Fragment }; const track = (fn, type, props, key) => { if (props && props.onSubmit) globalThis.lossHarness.handlers.push({ type, props }); if (type && type.name === 'LossForm') globalThis.lossHarness.formKeys.push(key); return fn(type, props, key); }; export const jsx = (...args) => track(one, ...args); export const jsxs = (...args) => track(many, ...args);",
                resolveDir: root,
            }));
        },
    }],
});

const artifact = join(temporary, 'transport-loss.mjs');
writeFileSync(artifact, bundle.outputFiles[0].text);
const { default: TransportLoss } = await import(pathToFileURL(artifact).href);

class Harness {
    cursor = 0;
    stateCursor = 0;
    refs = [];
    states = [];
    handlers = [];
    posts = [];
    formKeys = [];
    form = null;
    transform = data => data;
    pageProps = { auth: { user: { id: 10, role: 'admin' } } };

    ref(initial) {
        return this.refs[this.cursor++] ??= { current: initial };
    }

    state(initial) {
        return this.states[this.stateCursor++] ??= initial;
    }

    useForm(initial) {
        return this.form ??= {
            data: initial,
            errors: {},
            processing: false,
            setData: (key, value) => {
                if (typeof key === 'object') this.form.data = key;
                else this.form.data[key] = value;
            },
            transform: fn => {
                this.transform = fn;
            },
            post: (url, options) => {
                this.posts.push({ url, options, data: structuredClone(this.transform(this.form.data)) });
                this.form.processing = true;
            },
        };
    }

    submit() {
        const form = this.handlers.find(item => item.type === 'form');
        assert.ok(form);
        form.props.onSubmit({ preventDefault() {} });
    }

    finish() {
        this.form.processing = false;
        this.posts.at(-1).options.onFinish();
    }
}

async function renderLoss(value, manifestId = 41, version = 3, actorId = 10, basePath = '/hub/manifests') {
    const h = globalThis.lossHarness;
    h.cursor = 0;
    h.stateCursor = 0;
    h.handlers = [];
    h.pageProps = { auth: { user: { id: actorId, role: 'admin' } } };
    let view;
    const response = await createInertiaApp({
        page: { component: 'Components/Logistics/TransportLoss', props: {}, url: '/hub/manifests', version: null },
        resolve: () => () => createElement(TransportLoss, { value, manifestId, version, basePath }),
        setup: ({ App, props }) => {
            view = createElement(App, props);
            return view;
        },
        render: () => renderToStaticMarkup(view),
    });
    return response.body;
}

const eligible = {
    can_record: true,
    eligible_scope_kind: 'unarrived_bags',
    unarrived_bag_references: ['BAG-UNARRIVED-1'],
    record: null,
};

test('validates required confirmation, reason length, proof size/type and blocks invalid submission', async () => {
    const h = globalThis.lossHarness = new Harness();
    const html = await renderLoss(eligible);
    assert.match(html, /BAG-UNARRIVED-1/);
    assert.match(html, /Physical bag tags stay locked/);

    h.submit();
    assert.equal(h.posts.length, 0);

    Object.assign(h.form.data, {
        transport_loss_confirmed: true,
        reason: 'Too short',
        proof: { name: 'report.png', type: 'image/png', size: 1024 },
    });
    h.submit();
    assert.equal(h.posts.length, 0);

    Object.assign(h.form.data, {
        reason: 'Valid independent investigation details exceeding ten characters.',
        proof: { name: 'oversize.pdf', type: 'application/pdf', size: 5242881 },
    });
    h.submit();
    assert.equal(h.posts.length, 0);

    Object.assign(h.form.data, {
        proof: { name: 'bad.txt', type: 'text/plain', size: 512 },
    });
    h.submit();
    assert.equal(h.posts.length, 0);
});

test('submits strict allow-listed multipart payload once while in-flight and resets cleanly on success', async () => {
    const h = globalThis.lossHarness = new Harness();
    await renderLoss(eligible, 41, 3, 10, '/manifests');
    Object.assign(h.form.data, {
        transport_loss_confirmed: true,
        reason: '  Independently confirmed transit vehicle and unarrived sack never reached hub.  ',
        proof: { name: 'incident.pdf', type: 'application/pdf', size: 2048 },
        extra_field: 'ignored',
    });

    h.submit();
    h.submit();
    assert.equal(h.posts.length, 1);
    assert.equal(h.posts[0].url, '/manifests/41/whole-transport-loss');
    assert.equal(h.posts[0].options.forceFormData, true);
    assert.deepEqual(Object.keys(h.posts[0].data).sort(), [
        'expected_version',
        'proof',
        'reason',
        'request_token',
        'transport_loss_confirmed',
    ]);
    assert.equal(h.posts[0].data.reason, 'Independently confirmed transit vehicle and unarrived sack never reached hub.');
    h.finish();

    h.posts[0].options.onSuccess();
    assert.equal(h.form.data.proof, null);
    assert.equal(h.form.data.reason, '');
    assert.equal(h.form.data.transport_loss_confirmed, false);
});

test('keys loss form by actor, manifest, and version and hides private admin reason/proof from non-admin view', async () => {
    const h = globalThis.lossHarness = new Harness();
    await renderLoss(eligible, 41, 3, 10);
    await renderLoss(eligible, 41, 3, 11);
    await renderLoss(eligible, 42, 4, 11);
    assert.deepEqual(h.formKeys, ['10:41:3', '11:41:3', '11:42:4']);

    const floorHtml = await renderLoss({
        can_record: false,
        eligible_scope_kind: null,
        unarrived_bag_references: [],
        record: {
            reference: 'WTL-TEST-99',
            scope_kind: 'whole_transport',
            verified: true,
            lost_bags_count: 1,
            lost_bag_references: ['BAG-UNARRIVED-1'],
            created_at: '2026-11-14T10:00:00Z',
            reason: null,
            proof_url: null,
        },
    });
    assert.match(floorHtml, /WTL-TEST-99/);
    assert.doesNotMatch(floorHtml, /Download private loss evidence|<form/);

    const adminHtml = await renderLoss({
        can_record: false,
        eligible_scope_kind: null,
        unarrived_bag_references: [],
        record: {
            reference: 'WTL-TEST-99',
            scope_kind: 'whole_transport',
            verified: true,
            lost_bags_count: 1,
            lost_bag_references: ['BAG-UNARRIVED-1'],
            created_at: '2026-11-14T10:00:00Z',
            reason: 'Private admin note only.',
            proof_url: '/hub/manifests/41/whole-transport-loss/proof',
        },
    });
    assert.match(adminHtml, /Private admin note only\./);
    assert.match(adminHtml, /\/hub\/manifests\/41\/whole-transport-loss\/proof/);
});
