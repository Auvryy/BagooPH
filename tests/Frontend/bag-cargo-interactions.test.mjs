import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-bag-cargo-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const result = await build({ stdin: { contents: "export {default as Bags} from '@/Pages/Hub/MotherBags'; export {default as Manifests} from '@/Pages/Hub/Manifests'; export * from '@/Components/Logistics/CargoHandling';", loader: 'tsx', resolveDir: resolve(import.meta.dirname, '../..') }, bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', write: false,
    plugins: [{ name: 'cargo-interactions', setup(builder) {
        builder.onResolve({ filter: /^react$/ }, () => ({ path: 'react', namespace: 'cargo-hooks' }));
        builder.onLoad({ filter: /.*/, namespace: 'cargo-hooks' }, () => ({ contents: `export const useState = v => globalThis.cargoHarness.state(v); export const useRef = v => globalThis.cargoHarness.ref(v); export const useEffect = (f,d) => globalThis.cargoHarness.effect(f,d); export const useCallback = f => f; export const useMemo = f => f(); export default {};` }));
        builder.onResolve({ filter: /^@inertiajs\/react$/ }, () => ({ path: 'inertia', namespace: 'cargo-inertia' }));
        builder.onLoad({ filter: /.*/, namespace: 'cargo-inertia' }, () => ({ contents: `export const Head=()=>null; export const Link='a'; export const useForm=v=>globalThis.cargoHarness.form(v); export const router={post:(...args)=>globalThis.cargoHarness.posts.push(args)}; export const usePage=()=>({props:{auth:{user:{role:'logistics'}},flash:{}}});` }));
        builder.onResolve({ filter: /HubMap$/ }, () => ({ path: 'map', namespace: 'cargo-layout' }));
        builder.onResolve({ filter: /Layouts\/DashboardLayout$/ }, () => ({ path: 'layout', namespace: 'cargo-layout' }));
        builder.onLoad({ filter: /.*/, namespace: 'cargo-layout' }, () => ({ contents: 'export default props => props.children;' }));
    } }],
});
const file = join(temporary, 'cargo.mjs'); writeFileSync(file, result.outputFiles[0].text);
const ui = await import(pathToFileURL(file).href);
const hub = { id: 1, name: 'Origin Child', code: 'BH1', tier: 'local_bayan_hub', logistics_company_id: 1, parent_hub_id: 2, canScan: true };
const destination = { ...hub, id: 2, name: 'Mother Hub', code: 'MH2', tier: 'regional_mother_hub', canScan: false };
const bag = { id: 'bag-1', reference: 'BAG-ONE', physical_tag: 'SACK-001', packing_policy_version: 1, status: 'open', seal_number: null, version: 2, source_hub_id: 1, destination_hub_id: 2, manifest_id: null, parcels_count: 1, source: hub, destination, canPack: true, canOpen: false, parcels: [], events: [], capacity: { count: 1, weight_kg: 13, volume_cm3: 8000, max_count: 50, max_weight_kg: 25, max_volume_cm3: 300000 } };
const props = { bags: { data: [bag], links: [] }, selectedBag: bag, hubs: [hub, destination], manifests: [], creationToken: 'CREATE', packingToken: 'PACK', commandToken: 'COMMAND', packingAvailable: true, basePath: '/hub/mother-bags' };
function nodes(tree) { if (!tree || typeof tree !== 'object') return []; return [tree, ...[tree.props?.children].flat(Infinity).flatMap(nodes)]; }
function text(tree) { if (Array.isArray(tree)) return tree.map(text).join(' '); if (typeof tree === 'string' || typeof tree === 'number') return String(tree); return tree && typeof tree === 'object' ? text(tree.props?.children) : ''; }
function harness(t, Component = ui.Bags, initial = props) {
    const slots = [], effects = [], forms = []; let cursor = 0, formCursor = 0, queued = [];
    const h = { props: structuredClone(initial), posts: [], forms,
        state(value) { const id=cursor++; slots[id] ??= {value: typeof value === 'function' ? value() : value}; return [slots[id].value, next => { slots[id].value=typeof next === 'function' ? next(slots[id].value) : next; }]; },
        ref(value) { const id=cursor++; slots[id] ??= {current:value}; return slots[id]; },
        effect(fn,deps) { const id=cursor++; if (!effects[id] || deps.some((v,i)=>v!==effects[id][i])) { effects[id]=deps; queued.push(fn); } },
        form(value) { const id=formCursor++; if (!forms[id]) { const defaults=structuredClone(value); forms[id]={data:structuredClone(value),errors:{},processing:false,setData(key,val){this.data=typeof key==='string'?{...this.data,[key]:val}:key;},reset(...keys){for(const key of keys)this.data[key]=structuredClone(defaults[key]);},clearErrors(){this.errors={};},post(path,options={}){h.posts.push([path,structuredClone(this.data),options]);}}; } return forms[id]; },
        render() { cursor=0;formCursor=0;h.tree=Component(h.props);const pending=queued;queued=[];pending.forEach(f=>f());cursor=0;formCursor=0;h.tree=Component(h.props);return h.tree; },
        formContaining(label) { const node=nodes(h.tree).find(n=>n.type==='form' && text(n).includes(label));assert.ok(node,`Missing form ${label}`);return node; },
    };
    globalThis.cargoHarness=h;globalThis.route=name=>name ? `/${name.replaceAll('.','/')}` : {current:()=>false};
    t.after(()=>{delete globalThis.cargoHarness;delete globalThis.route;});return h;
}
const event = {preventDefault(){}};

test('packing page exposes automatic packing, actual readings, reusable sack identity and separate limits', t=>{
    const h=harness(t);h.render();const content=text(h.tree);
    for(const phrase of ['Scan into the next open bag','Reusable sack tag','SACK-001','Packing capacity','Find bag & add parcel'])assert.ok(content.includes(phrase),phrase);
    const meters=nodes(h.tree).filter(n=>n.type===ui.CapacityMeter);assert.equal(meters.length,3);
    assert.equal(meters.find(n=>n.props.label==='Weight').props.value,13);
});
test('two rapid packing submits send one command and preserve the actual barcode and readings', t=>{
    const h=harness(t);h.render();Object.assign(h.forms[1].data,{source_hub_id:'1',barcode:'BGO-ACTUAL',handling:{...ui.emptyHandling(),weight_kg:'13',length_cm:'20',width_cm:'20',height_cm:'20',confirmed:true}});h.render();
    const form=h.formContaining('Find bag & add parcel');form.props.onSubmit(event);form.props.onSubmit(event);
    assert.equal(h.posts.length,1);assert.equal(h.posts[0][0],'/hub/mother-bags/pack');assert.equal(h.posts[0][1].barcode,'BGO-ACTUAL');assert.equal(h.posts[0][1].handling.weight_kg,'13');assert.equal(h.posts[0][1].request_token,'PACK');
});
test('failed packing retains user input and retry token even when validation redirects refresh server tokens', t=>{
    const h=harness(t);h.render();h.forms[1].setData('barcode','BGO-KEEP');h.forms[1].setData('physical_tag','SACK-KEEP');h.render();h.formContaining('Find bag & add parcel').props.onSubmit(event);
    h.forms[1].errors={packing:'Measure this outer package first.'};h.posts[0][2].onFinish();h.props.packingToken='NEW-SERVER-TOKEN';h.render();
    assert.equal(h.forms[1].data.barcode,'BGO-KEEP');assert.equal(h.forms[1].data.physical_tag,'SACK-KEEP');assert.equal(h.forms[1].data.request_token,'PACK');assert.match(text(h.tree),/Measure this outer package first/);
    h.formContaining('Find bag & add parcel').props.onSubmit(event);assert.equal(h.posts[1][1].request_token,'PACK');
});
test('successful packing clears only the scanned parcel and rotates its request token',t=>{
    const h=harness(t);h.render();Object.assign(h.forms[1].data,{source_hub_id:'1',barcode:'BGO-ONE',physical_tag:'SACK-001'});h.render();h.formContaining('Find bag & add parcel').props.onSubmit(event);h.posts[0][2].onSuccess();h.posts[0][2].onFinish();
    assert.equal(h.forms[1].data.barcode,'');assert.equal(h.forms[1].data.source_hub_id,'1');assert.equal(h.forms[1].data.physical_tag,'SACK-001');assert.notEqual(h.forms[1].data.request_token,'PACK');
});
test('bag validation preserves typed seal and errors until a committed bag version changes',t=>{
    const h=harness(t);h.render();h.forms[2].setData('seal_number','SL-TYPED');h.forms[2].errors={bag:'This seal was already used.'};h.props.commandToken='VALIDATION-REFRESH';h.render();
    assert.equal(h.forms[2].data.seal_number,'SL-TYPED');assert.match(text(h.tree),/This seal was already used/);
    h.props.selectedBag.version++;h.render();assert.equal(h.forms[2].data.seal_number,'');assert.equal(h.forms[2].data.request_token,'VALIDATION-REFRESH');
});
test('missing migration keeps existing history visible with a clear blocker and no automatic submission',t=>{
    const h=harness(t,ui.Bags,{...props,packingAvailable:false});h.render();assert.match(text(h.tree),/additive database migration/);assert.doesNotMatch(text(h.tree),/Find bag & add parcel/);assert.match(text(h.tree),/BAG-ONE/);
});
test('unknown capacity is not presented as measured zero',()=>{
    const html=renderToStaticMarkup(createElement(ui.CapacityMeter,{value:null,max:25,label:'Weight',unit:'kg'}));assert.match(html,/Unknown/);assert.doesNotMatch(html,/>0 \/ 25/);
});
test('readings show their provenance and never pretend a declaration is a scale result',()=>{
    const profile={weight_kg:1,length_cm:20,width_cm:20,height_cm:20,baggable:true};
    assert.match(renderToStaticMarkup(createElement(ui.HandlingSummary,{value:{...profile,source:'purchased_declaration'}})),/Purchased declaration/);
    assert.match(renderToStaticMarkup(createElement(ui.HandlingSummary,{value:{...profile,source:'operator_reading'}})),/Operator reading/);
});
const manifest={id:1,reference:'MF-ONE',type:'feeder',direction:'outbound',status:'draft',version:2,included_count:1,source:hub,destination,vehicle_plate:'QA-ONE',driver_name:'Named driver',dispatched_at:null,received_at:null,canManage:true,canLoad:true,canClose:false,canReceive:false,parcels:[{id:9,tracking_number:'BGO-LOOSE',included:true,status:'arrived_at_origin_hub',received_at:null,receipt_condition:null,cargo_mode:'loose'}],events:[],discrepancies:[],cargoCapacity:{weight_kg:20,max_weight_kg:1000,loose_count:1,count:1}};
function manifestProps(selected=manifest){return {manifests:{data:[selected],links:[]},selectedManifest:selected,canCreate:false,creationToken:null,commandToken:'MANIFEST-TOKEN',basePath:'/hub/manifests',hubs:[hub,destination],vehicles:[],sourceHubIds:[1]};}
test('loose cargo loading submits the selected treatment and actual handling readings once',t=>{
    const h=harness(t,ui.Manifests,manifestProps());h.render();
    nodes(h.tree).find(n=>n.type==='select'&&nodes(n).some(c=>c.props?.value==='loose')).props.onChange({target:{value:'loose'}});h.render();
    nodes(h.tree).find(n=>n.type===ui.HandlingFields).props.onChange({weight_kg:'20',length_cm:'20',width_cm:'20',height_cm:'20',confirmed:true});h.render();
    const form=h.formContaining('Actual outbound waybill');nodes(form).find(n=>n.type==='input').props.onChange({target:{value:'BGO-LOOSE'}});h.render();h.formContaining('Actual outbound waybill').props.onSubmit(event);h.formContaining('Actual outbound waybill').props.onSubmit(event);
    assert.equal(h.posts.length,1);assert.equal(h.posts[0][1].cargo_mode,'loose');assert.equal(h.posts[0][1].barcode,'BGO-LOOSE');assert.equal(h.posts[0][1].handling.weight_kg,'20');
});
test('bag members cannot be split through the individual remove control',t=>{
    const h=harness(t,ui.Manifests,manifestProps({...manifest,parcels:[{...manifest.parcels[0],cargo_mode:'bag'}]}));h.render();
    const button=nodes(h.tree).find(n=>n.type==='button'&&text(n).includes('Remove'));assert.ok(button);assert.equal(button.props.disabled,true);assert.match(text(h.tree),/Sealed bag member/);
});

test('opening a different manifest clears the previous parcel readings and treatment',t=>{
    const h=harness(t,ui.Manifests,manifestProps());h.render();
    nodes(h.tree).find(n=>n.type==='select'&&nodes(n).some(c=>c.props?.value==='loose')).props.onChange({target:{value:'loose'}});h.render();
    nodes(h.tree).find(n=>n.type===ui.HandlingFields).props.onChange({...ui.emptyHandling(),weight_kg:'20'});h.render();
    h.props.selectedManifest={...h.props.selectedManifest,id:2};h.render();
    const selection=nodes(h.tree).find(n=>n.type==='select'&&nodes(n).some(c=>c.props?.value==='loose'));assert.equal(selection.props.value,'');assert.equal(nodes(h.tree).filter(n=>n.type===ui.HandlingFields).length,0);
});
