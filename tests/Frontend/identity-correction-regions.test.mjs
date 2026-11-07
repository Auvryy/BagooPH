import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

const root = resolve(import.meta.dirname, '../..');
const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-region-correction-'));
after(() => { rmSync(temporary, { recursive: true, force: true }); delete globalThis.regionCorrection; });
const mocks = {
    react: `export * from 'react'; export { default } from 'react'; export const useEffect = () => {}; export const useState = initial => globalThis.regionCorrection.state(initial);`,
    '@inertiajs/react': `export const router = {}; export const useForm = initial => { const h = globalThis.regionCorrection; h.data ??= initial; return { data: h.data, errors: {}, clearErrors() {}, setError(key, message) { h.errors = { [key]: message }; }, setData(key, value) { h.data = typeof key === 'function' ? key(h.data) : { ...h.data, [key]: value }; } }; };`,
    axios: `const axios = { post: async (url, body) => { globalThis.regionCorrection.post = { url, body }; }, isAxiosError: () => false }; export default axios;`,
    '@/utils/accountRequests': `export const refreshPage = async () => {}; export const requestErrors = () => ({});`,
    'react/jsx-runtime': `import { jsx as realJsx, jsxs as realJsxs, Fragment } from 'react/jsx-runtime'; export { Fragment }; const track = (fn, type, props, key) => { if (props.onChange || props.onSubmit) globalThis.regionCorrection.handlers.push({ type, props }); return fn(type, props, key); }; export const jsx = (type, props, key) => track(realJsx, type, props, key); export const jsxs = (type, props, key) => track(realJsxs, type, props, key);`,
};
const bundle = await build({ entryPoints: [resolve(root, 'resources/js/Components/IdentityCorrectionPanel.tsx')], bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', write: false,
    plugins: [{ name: 'region-correction-controls', setup(builder) {
        builder.onResolve({ filter: /^(react|@inertiajs\/react|axios|@\/utils\/accountRequests|react\/jsx-runtime)$/ }, args => args.importer.endsWith('/IdentityCorrectionPanel.tsx') ? { path: args.path, namespace: 'mock' } : undefined);
        builder.onLoad({ filter: /.*/, namespace: 'mock' }, args => ({ contents: mocks[args.path], resolveDir: root }));
    } }],
});
const modulePath = join(temporary, 'panel.mjs');
writeFileSync(modulePath, bundle.outputFiles[0].text);
const { default: Panel } = await import(pathToFileURL(modulePath).href);

const subject = {
    id: 7, name: 'Company owner', role: 'logistics', shop_id: null, source_token: 'current-source',
    values: { birthday: '1990-01-01', service_areas: ['NCR', 'Region IV-A'] },
    fields: [{ key: 'service_areas', label: 'Service regions', type: 'multiselect', options: ['NCR', 'Region III', 'Region IV-A'] }],
    required_documents: [], requests: [], provenance: { source: 'reviewed', kyc_decision_id: 1, shop_review_decision_id: null },
};
class Harness {
    values = []; index = 0; handlers = [];
    state(initial) { const index = this.index++; if (!(index in this.values)) this.values[index] = initial; return [this.values[index], value => { this.values[index] = typeof value === 'function' ? value(this.values[index]) : value; }]; }
    render() { this.index = 0; this.handlers = []; return renderToStaticMarkup(createElement(Panel, { subject, categories: [] })); }
}

test('owner selects several regions and submits a list while existing regions remain checked', async () => {
    const h = new Harness(); globalThis.regionCorrection = h;
    h.render();
    h.handlers.find(item => item.type === 'select').props.onChange({ target: { value: 'service_areas' } });
    let html = h.render();
    assert.match(html, /Currently recorded:.*NCR, Region IV-A/);
    assert.equal(h.handlers.filter(item => item.props.type === 'checkbox').length, 3);
    const region = text => h.handlers.find(item => item.props.type === 'checkbox' && item.props.checked === text);
    assert.ok(region(true));
    h.handlers.filter(item => item.props.type === 'checkbox')[1].props.onChange({ target: { checked: true } });
    h.render();
    h.handlers.find(item => item.type === 'textarea').props.onChange({ target: { value: 'Add our new regional coverage.' } });
    h.render();
    await h.handlers.find(item => item.type === 'form').props.onSubmit({ preventDefault() {} });
    assert.equal(h.post.url, '/account/identity-corrections');
    assert.deepEqual([...h.post.body.entries()].filter(([key]) => key.startsWith('changes[')), [
        ['changes[service_areas][0]', 'NCR'], ['changes[service_areas][1]', 'Region IV-A'], ['changes[service_areas][2]', 'Region III'],
    ]);
    assert.equal(h.post.body.get('source_token'), 'current-source');
    assert.equal(h.post.body.get('reason'), 'Add our new regional coverage.');
});

 test('clearing all regions explains the required selection before sending', async () => {
    const h = new Harness(); globalThis.regionCorrection = h;
    h.render();
    h.handlers.find(item => item.type === 'select').props.onChange({ target: { value: 'service_areas' } });
    h.render();
    h.handlers.filter(item => item.props.type === 'checkbox')[0].props.onChange({ target: { checked: false } });
    h.render();
    h.handlers.filter(item => item.props.type === 'checkbox')[2].props.onChange({ target: { checked: false } });
    h.render();
    await h.handlers.find(item => item.type === 'form').props.onSubmit({ preventDefault() {} });
    assert.equal(h.post, undefined);
    assert.equal(h.errors.changes, 'Choose at least one value for this detail.');
});
