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
const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-shared-trip-'));
after(() => { rmSync(temporary, { recursive: true, force: true }); delete globalThis.tripHarness; });
const built = await build({ stdin: { contents: "export { default } from '@/Components/Logistics/SharedTripPanel';", resolveDir: root, loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', write: false, plugins: [{ name: 'trip-controls', setup(builder) {
        const target = /Components\/Logistics\/SharedTripPanel\.tsx$/;
        builder.onResolve({ filter: /^react$/ }, args => target.test(args.importer) ? {path:'react',namespace:'trip-probe'} : undefined);
        builder.onLoad({ filter: /^react$/, namespace:'trip-probe' }, () => ({contents:"export * from 'react'; export const useEffect=(run,deps)=>globalThis.tripHarness.effects.push({run,deps});",resolveDir:root}));
        builder.onResolve({ filter: /^@inertiajs\/react$/ }, args => target.test(args.importer) ? { path: 'inertia', namespace: 'trip-probe' } : undefined);
        builder.onLoad({ filter: /^inertia$/, namespace: 'trip-probe' }, () => ({ contents: "export * from '@inertiajs/react'; export const useForm = initial => globalThis.tripHarness.useForm(initial);", resolveDir: root }));
        builder.onResolve({ filter: /^react\/jsx-runtime$/ }, args => target.test(args.importer) ? { path: 'jsx', namespace: 'trip-probe' } : undefined);
        builder.onLoad({ filter: /^jsx$/, namespace: 'trip-probe' }, () => ({ contents: `import {jsx as real,jsxs as many,Fragment} from 'react/jsx-runtime'; export {Fragment}; const track=(fn,type,props,key)=>{if(props.onSubmit||props.onClick||props.onChange)globalThis.tripHarness.handlers.push({type,props});return fn(type,props,key)};export const jsx=(...args)=>track(real,...args);export const jsxs=(...args)=>track(many,...args);`, resolveDir: root }));
    } }] });
const artifact = join(temporary, 'trip.mjs'); writeFileSync(artifact, built.outputFiles[0].text);
const { default: Panel } = await import(pathToFileURL(artifact).href);
class Harness {
    handlers = []; posts = []; form;
    effects = []; previousEffects = [];
    flushEffects() { this.effects.forEach((effect,index)=>{const before=this.previousEffects[index];if(!before||effect.deps.some((value,i)=>value!==before[i])){effect.run();this.previousEffects[index]=[...effect.deps];}}); }
    useForm(initial) { return this.form ??= { data: initial, errors: {}, processing: false,
        setData: (key, value) => typeof key === 'object' ? this.form.data = key : this.form.data[key] = value,
        clearErrors: () => this.form.errors = {},
        post: (url, options) => { this.posts.push({ url, options, data: structuredClone(this.form.data) }); this.form.processing = true; } }; }
    control(predicate) { const node=this.handlers.find(predicate); assert.ok(node, 'actual rendered control exists'); return node.props; }
    click(text) { this.control(node => node.type === 'button' && node.props.children === text).onClick(); }
}
const members = [{id: 2, reference: 'MFT-FORWARD', direction: 'outbound'}, {id: 3, reference: 'MFT-RTS', direction: 'return'}, {id: 4, reference: 'MFT-BUYER-RETURN', direction: 'buyer_return'}];
const defaults = {trip: null, candidates: members, selectedId: 2, token: 'original-request-token', basePath: '/hub/manifests'};
async function render(props={}) {
    const h=globalThis.tripHarness; h.handlers=[]; h.effects=[];
    let view;
    const page=await createInertiaApp({page:{component:'Trip',props:{...defaults,...props},url:'/hub/manifests/2',version:null},resolve:()=>Panel,
        setup:({App,props})=>{view=createElement(App,props);return view;},render:()=>renderToStaticMarkup(view)});
    h.flushEffects(); return page.body;
}

test('grouping submits the chosen original manifest identities and retains the selected manifest', async()=>{
    const h=globalThis.tripHarness=new Harness();
    const html=await render();
    assert.match(html,/Prepare shared trip/); assert.match(html,/type="checkbox"[^>]*disabled=""/);
    h.control(node=>node.type==='input'&&!node.props.disabled).onChange({target:{checked:true}});
    await render(); h.control(node=>node.type==='form').onSubmit({preventDefault(){}});
    assert.deepEqual(h.posts[0].data.manifest_ids,[2,3]); assert.equal(h.posts[0].url,'/hub/manifests/trips');
    h.control(node=>node.type==='form').onSubmit({preventDefault(){}}); assert.equal(h.posts.length,1);
});
test('ready groups expose one departure and cancellation with the retained version',async()=>{
    const h=globalThis.tripHarness=new Harness();
    const html=await render({trip:{id:7,reference:'TRIP-ORIGINAL',version:3,status:'ready',canManage:true,canDepart:true,canClose:false,manifests:members}});
    assert.match(html,/Record shared departure/); assert.match(html,/Cancel grouping/); assert.doesNotMatch(html,/Prepare shared trip|Close received trip/);
    h.click('Record shared departure'); assert.equal(h.posts[0].url,'/hub/manifests/trips/7/dispatch'); assert.equal(h.posts[0].data.version,3);
    h.click('Record shared departure'); assert.equal(h.posts.length,1);
});
test('partial destination views retain separate manifests and offer no cancellation or departure',async()=>{
    globalThis.tripHarness=new Harness();
    const html=await render({basePath:'/manifests',trip:{id:7,reference:'TRIP-ORIGINAL',version:4,status:'dispatched',canManage:false,canDepart:false,canClose:true,
        manifests:members.map((member,i)=>({...member,status:i===0?'received':'dispatched',outstanding:i===0?0:1}))}});
    assert.match(html,/Close received trip/); assert.match(html,/1 awaiting receipt/); assert.match(html,/href="\/manifests\/4"/);
    assert.doesNotMatch(html,/Record shared departure|Cancel grouping/);
});
test('failed grouping keeps the saved selection and shows the actual error',async()=>{
    const h=globalThis.tripHarness=new Harness(); await render();
    h.form.data.manifest_ids=[2,4]; h.form.errors={manifest:'Combined cargo exceeds capacity.'};
    const html=await render(); assert.match(html,/role="alert"/); assert.match(html,/Combined cargo exceeds capacity/);
    assert.deepEqual(h.form.data.manifest_ids,[2,4]); assert.equal(h.posts.length,0);
});
test('no compatible second manifest produces no grouping controls',async()=>{
    globalThis.tripHarness=new Harness(); assert.equal(await render({candidates:[members[0]]}),'');
});
test('a validation redirect with a fresh page token preserves selection and retry identity',async()=>{
    const h=globalThis.tripHarness=new Harness(); await render();
    h.form.data.manifest_ids=[2,4];h.form.errors={manifest:'Recheck the actual selected cargo.'};
    const original=h.form.data.request_token;
    await render({token:'fresh-page-token-after-validation'});
    assert.deepEqual(h.form.data.manifest_ids,[2,4]);assert.equal(h.form.data.request_token,original);
    assert.equal(h.form.errors.manifest,'Recheck the actual selected cargo.');
});
test('one buyer return can prepare a trip without a minimum parcel-count delay',async()=>{
    const h=globalThis.tripHarness=new Harness();
    const html=await render({selectedId:4,candidates:[members[2]]});assert.match(html,/Prepare shared trip/);
    h.control(node=>node.type==='form').onSubmit({preventDefault(){}});assert.deepEqual(h.posts[0].data.manifest_ids,[4]);
});
