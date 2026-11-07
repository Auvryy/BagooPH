import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
const root = resolve(import.meta.dirname, '../..');
const tmp = mkdtempSync(join(import.meta.dirname, '.tmp-checkout-address-'));
after(() => { rmSync(tmp, { recursive: true, force: true }); delete globalThis.addressDisclosure; });
const bundle = await build({ entryPoints: [join(root, 'resources/js/Components/CheckoutAddressDisclosure.tsx')], bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', write: false,
    plugins: [{ name: 'address-controls', setup(b) {
        b.onResolve({ filter: /^(react|react\/jsx-runtime)$/ }, args => args.importer.endsWith('/CheckoutAddressDisclosure.tsx') ? { path: args.path, namespace: 'controls' } : undefined);
        b.onLoad({ filter: /.*/, namespace: 'controls' }, args => ({ resolveDir: root, contents: args.path === 'react' ? `export * from 'react'; export const useState = value => globalThis.addressDisclosure.state(value); export const useEffect = (fn,deps) => globalThis.addressDisclosure.effect(fn,deps);` : `import { jsx as j, jsxs as js, Fragment } from 'react/jsx-runtime'; export { Fragment }; const track=(fn,type,props,key)=>{if(props.onClick||props.onInvalidCapture) globalThis.addressDisclosure.handlers.push({type,props}); return fn(type,props,key);}; export const jsx=(...args)=>track(j,...args); export const jsxs=(...args)=>track(js,...args);` }));
    } }],
});
const file = join(tmp, 'view.mjs'); writeFileSync(file, bundle.outputFiles[0].text);
const { default: Disclosure } = await import(pathToFileURL(file).href);
class Harness {
    value; handlers = []; lastError; effects = [];
    state(initial) { this.value ??= initial; return [this.value, next => { this.value = typeof next === 'function' ? next(this.value) : next; }]; }
    effect(fn,deps) { if (this.lastError !== deps[0]) { this.lastError = deps[0]; this.effects.push(fn); } }
    render(props = {}) { globalThis.addressDisclosure = this; this.handlers = []; const html = renderToStaticMarkup(createElement(Disclosure, { summary: 'Maria, 123 Mabini Street, Pasig', initiallyExpanded: false, ...props }, createElement('input', { required: true, defaultValue: 'Retained address' }))); this.effects.splice(0).forEach(fn => fn()); return html; }
}
test('saved address expands and collapses without discarding the editor values', () => {
    const h = new Harness(); let html = h.render();
    assert.match(html, /aria-expanded="false"/); assert.match(html, /hidden=""/);
    h.handlers.find(x => x.type === 'button').props.onClick(); html = h.render();
    assert.match(html, /aria-expanded="true"/); assert.doesNotMatch(html, /hidden=""/); assert.match(html, /value="Retained address"/);
    h.handlers.find(x => x.type === 'button').props.onClick(); html = h.render();
    assert.match(html, /hidden=""/); assert.match(html, /value="Retained address"/);
});
test('new or incomplete addresses open immediately and server errors reopen a collapsed editor', () => {
    const h = new Harness(); assert.match(h.render({ initiallyExpanded: true }), /aria-expanded="true"/);
    h.handlers.find(x => x.type === 'button').props.onClick(); h.render();
    h.render({ hasErrors: true }); assert.match(h.render({ hasErrors: true }), /aria-expanded="true"/);
});
test('native required-field validation reveals and focuses hidden invalid fields', () => {
    const h = new Harness(); h.render(); let focused = false, scrolled = false, prevented = false, secondFocused = false, frame;
    const prior = globalThis.requestAnimationFrame; globalThis.requestAnimationFrame = fn => { frame = fn; };
    try {
        h.handlers.find(x => x.type === 'section').props.onInvalidCapture({ preventDefault() { prevented = true; }, target: { focus() { focused = true; }, scrollIntoView() { scrolled = true; } } });
        h.handlers.find(x => x.type === 'section').props.onInvalidCapture({ preventDefault() {}, target: { focus() { secondFocused = true; }, scrollIntoView() {} } });
        assert.match(h.render(), /aria-expanded="true"/); frame(); assert.ok(prevented && focused && scrolled); assert.equal(secondFocused, false);
    } finally { globalThis.requestAnimationFrame = prior; }
});
