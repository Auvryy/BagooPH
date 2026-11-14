import {after,test} from 'node:test';
import assert from 'node:assert/strict';
import {mkdtempSync,rmSync,writeFileSync} from 'node:fs';
import {join,resolve} from 'node:path';
import {pathToFileURL} from 'node:url';
import {build} from 'esbuild';
import {createElement} from 'react';
import {renderToStaticMarkup} from 'react-dom/server';
import {createInertiaApp} from '@inertiajs/react';
const root=resolve(import.meta.dirname,'../..');
const temporary=mkdtempSync(join(import.meta.dirname,'.tmp-return-trip-packing-'));
after(()=>{rmSync(temporary,{recursive:true,force:true});delete globalThis.packingHarness;});
const built=await build({stdin:{contents:"export {default} from '@/Components/Logistics/ReturnTripPackingPanel';",resolveDir:root,loader:'tsx'},bundle:true,platform:'node',format:'esm',packages:'external',jsx:'automatic',write:false,
    plugins:[{name:'packing-controls',setup(builder){const target=/Components\/Logistics\/ReturnTripPackingPanel\.tsx$/;
        builder.onResolve({filter:/^@inertiajs\/react$/},args=>target.test(args.importer)?{path:'inertia',namespace:'packing-probe'}:undefined);
        builder.onLoad({filter:/^inertia$/,namespace:'packing-probe'},()=>({contents:"export * from '@inertiajs/react';export const useForm=initial=>globalThis.packingHarness.useForm(initial);",resolveDir:root}));
        builder.onResolve({filter:/^react\/jsx-runtime$/},args=>target.test(args.importer)?{path:'jsx',namespace:'packing-probe'}:undefined);
        builder.onLoad({filter:/^jsx$/,namespace:'packing-probe'},()=>({contents:`import{jsx as real,jsxs as many,Fragment}from'react/jsx-runtime';export{Fragment};const track=(fn,type,props,key)=>{if(props.onSubmit||props.onChange||props.onClick)globalThis.packingHarness.handlers.push({type,props});return fn(type,props,key)};export const jsx=(...args)=>track(real,...args);export const jsxs=(...args)=>track(many,...args);`,resolveDir:root}));
    }}]});
const artifact=join(temporary,'packing.mjs');writeFileSync(artifact,built.outputFiles[0].text);const{default:Panel}=await import(pathToFileURL(artifact).href);
class Harness{
    forms={};handlers=[];posts=[];
    useForm(initial){const key='bag_version'in initial?'bag':'pack';if(this.forms[key])return this.forms[key];let transform=value=>value;
        const form={data:initial,errors:{},processing:false,setData:(name,value)=>{if(typeof name==='object')form.data=name;else form.data[name]=value;},clearErrors:()=>{form.errors={};},
            reset:(...keys)=>keys.forEach(name=>{form.data[name]=structuredClone(initial[name]);}),transform:fn=>{transform=fn;},
            post:(url,options)=>{this.posts.push({url,options,data:structuredClone(transform(form.data))});form.processing=true;}};return this.forms[key]=form;}
    submit(label){const node=this.handlers.find(node=>node.type==='form'&&node.props['aria-label']===label);assert.ok(node,'actual labelled form exists');node.props.onSubmit({preventDefault(){}});}
}
const parcel={barcode:'BRT-ONE',status:'at_hub',baggable:true,weight_kg:2.5,manifest_id:12};
const bag={id:'bag-one',reference:'BAG-ONE',physical_tag:'SACK-ONE',seal_number:null,status:'open',version:2,barcodes:['BRT-ONE'],capacity:{count:1,weight_kg:2.5,volume_cm3:8000,max_count:50,max_weight_kg:25,max_volume_cm3:300000}};
const trip={id:7,status:'ready',version:4,canDepart:true,canReceive:false,returnParcels:[parcel],returnBags:[bag]};
async function render(changes={},basePath='/hub/manifests'){const h=globalThis.packingHarness;h.handlers=[];let view;
    const page=await createInertiaApp({page:{component:'Packing',props:{trip:{...trip,...changes},basePath},url:basePath+'/12',version:null},resolve:()=>Panel,
        setup:({App,props})=>{view=createElement(App,props);return view;},render:()=>renderToStaticMarkup(view)});return page.body;}

test('packing requires a parcel and physical sack confirmation without submitting custody or money',async()=>{
    const h=globalThis.packingHarness=new Harness();const html=await render();
    assert.match(html,/Pack one actual return/);assert.match(html,/Permanent sack tag/);assert.match(html,/1\/50 parcels/);assert.match(html,/2.5\/25 kg/);
    Object.assign(h.forms.pack.data,{barcode:' brt-one ',physical_tag:' sack-one ',physical_checked:true});
    h.submit('Pack return into sack');h.submit('Pack return into sack');assert.equal(h.posts.length,1);
    assert.equal(h.posts[0].url,'/hub/manifests/trips/7/return-sacks');assert.equal(h.posts[0].data.barcode,'BRT-ONE');assert.equal(h.posts[0].data.physical_tag,'SACK-ONE');
    assert.equal(h.posts[0].data.version,4);assert.equal('amount'in h.posts[0].data,false);assert.equal('status'in h.posts[0].data,false);
});
test('sealing submits only the actual master code and new numbered seal',async()=>{
    const h=globalThis.packingHarness=new Harness();await render();Object.assign(h.forms.bag.data,{action:'seal',barcode:' bag-one ',seal_number:' new-seal ',return_barcode:'BRT-OTHER',notes:'Stale removal note.'});
    const html=await render();assert.match(html,/Numbered seal/);h.submit('Manage sack SACK-ONE');
    assert.equal(h.posts[0].url,'/hub/manifests/trips/7/return-sacks/bag-one/seal');assert.equal(h.posts[0].data.bag_version,2);
    assert.equal(h.posts[0].data.seal_number,'NEW-SEAL');assert.equal('return_barcode'in h.posts[0].data,false);assert.equal('notes'in h.posts[0].data,false);
});
test('the destination gets intact-seal confirmation and no source packing control',async()=>{
    const h=globalThis.packingHarness=new Harness();const changes={status:'dispatched',canDepart:false,canReceive:true,returnBags:[{...bag,status:'in_transit',seal_number:'SEALED-ONE',version:4}]};
    let html=await render(changes,'/manifests');assert.doesNotMatch(html,/Pack one actual return/);h.forms.bag.data.action='open';html=await render(changes,'/manifests');
    assert.match(html,/intact numbered seal/);Object.assign(h.forms.bag.data,{barcode:'BAG-ONE',seal_number:'SEALED-ONE',seal_intact:true});h.submit('Manage sack SACK-ONE');
    assert.equal(h.posts[0].url,'/manifests/trips/7/return-sacks/bag-one/open');assert.equal(h.posts[0].data.seal_intact,true);
});
test('loose cargo and read-only actors get no fabricated sack action',async()=>{
    globalThis.packingHarness=new Harness();const html=await render({canDepart:false,canReceive:false,returnParcels:[{...parcel,baggable:false}],returnBags:[]});
    assert.match(html,/Individual loose cargo/);assert.doesNotMatch(html,/<form|Pack one actual return/);
});
test('a failed request retains the actual scan and original retry token',async()=>{
    const h=globalThis.packingHarness=new Harness();await render();Object.assign(h.forms.pack.data,{barcode:'BRT-ONE',physical_tag:'SACK-ONE',physical_checked:true});
    h.submit('Pack return into sack');const original=h.posts[0];h.forms.pack.processing=false;original.options.onFinish();h.forms.pack.errors={manifest:'This sack already reached its actual capacity.'};
    const html=await render();assert.match(html,/actual capacity/);assert.equal(h.forms.pack.data.barcode,'BRT-ONE');h.submit('Pack return into sack');assert.equal(h.posts[1].data.request_token,original.data.request_token);
});
