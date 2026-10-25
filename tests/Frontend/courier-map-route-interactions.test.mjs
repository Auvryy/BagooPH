import {after,test} from 'node:test';
import assert from 'node:assert/strict';
import {mkdtempSync,rmSync,writeFileSync} from 'node:fs';
import {resolve,join} from 'node:path';
import {pathToFileURL} from 'node:url';
import {build} from 'esbuild';
const dir=mkdtempSync(join(import.meta.dirname,'.tmp-map-route-'));after(()=>rmSync(dir,{recursive:true,force:true}));
const bundle=await build({stdin:{contents:"export {default} from '@/Components/CourierJobMap';",resolveDir:resolve(import.meta.dirname,'../..'),loader:'tsx'},bundle:true,packages:'external',platform:'node',format:'esm',jsx:'automatic',write:false,
 plugins:[{name:'map-route-harness',setup(b){
 b.onResolve({filter:/^react$/},()=>({path:'react',namespace:'hooks'}));b.onLoad({filter:/.*/,namespace:'hooks'},()=>({contents:`export const useState=v=>globalThis.mapRoute.state(v);export const useRef=v=>globalThis.mapRoute.ref(v);export const useEffect=(f,d)=>globalThis.mapRoute.effect(f,d);`}));
 b.onResolve({filter:/Components\/CourierUI$/},()=>({path:'ui',namespace:'ui'}));b.onLoad({filter:/.*/,namespace:'ui'},()=>({contents:`export const CourierBadge='span',CourierPanel='section',courierButton='',courierClasses=(...v)=>v.filter(Boolean).join(' ');`}));
 b.onResolve({filter:/^leaflet$/},()=>({path:'leaflet',namespace:'leaflet'}));b.onLoad({filter:/.*/,namespace:'leaflet'},()=>({contents:`export const map=()=>globalThis.mapRoute.instance;export const tileLayer=()=>globalThis.mapRoute.tiles;export const marker=()=>globalThis.mapRoute.marker;export const divIcon=v=>v;export const layerGroup=()=>globalThis.mapRoute.layer;export const polyline=(points,options)=>{globalThis.mapRoute.lines.push({points,options});return {addTo(){return this}}};export const circleMarker=()=>({addTo(){return this},bindTooltip(){return this}});export const latLngBounds=p=>p;`}));
 }}]});const file=join(dir,'map.mjs');writeFileSync(file,bundle.outputFiles[0].text);const MapView=(await import(pathToFileURL(file).href)).default;
const tick=async()=>{for(let i=0;i<30;i++)await Promise.resolve()};
const nodes=tree=>!tree||typeof tree!=='object'?[]:[tree,...[tree.props?.children].flat(Infinity).flatMap(nodes)];
const text=tree=>typeof tree==='string'?tree:Array.isArray(tree)?tree.map(text).join(''):tree&&typeof tree==='object'?text(tree.props?.children):'';
function harness(t){
 const original=Object.fromEntries(['document','window','navigator','fetch'].map(n=>[n,Object.getOwnPropertyDescriptor(globalThis,n)]));const slots=[],effects=[];let cursor=0,pending=[];
 const h={calls:[],lines:[],props:{job:{key:'pickup-7',status:'picked_up',trackingNumber:'BGO-ONE',stage:'Carrying',preview:false,stopLabel:'Origin hub',place:{name:'Hub',latitude:14.17,longitude:121.24}}},
 state(initial){const i=cursor++;if(!(i in slots))slots[i]=typeof initial==='function'?initial():initial;return[slots[i],v=>{slots[i]=typeof v==='function'?v(slots[i]):v}]},ref(v){return this.state(()=>({current:v}))[0]},effect(fn,deps){const i=cursor++;const old=effects[i];if(!old||deps.some((v,k)=>!Object.is(v,old.deps[k])))pending.push(()=>{old?.cleanup?.();effects[i]={deps,cleanup:fn()}})},
 instance:{attributionControl:{setPrefix(){}},setView(){return this},remove(){},invalidateSize(){},fitBounds(){},zoomIn(){},zoomOut(){}},tiles:{on(){return this},addTo(){}},marker:{addTo(){return this},bindTooltip(){return this}},layer:{addTo(){return this},clearLayers(){h.lines=[]},remove(){h.lines=[]}},
 render(){cursor=0;pending=[];this.tree=MapView(this.props);for(const node of nodes(this.tree)){const ref=node.props?.ref??node.ref;if(ref&&node.props?.role==='region')ref.current={}}for(const run of pending)run();return this.tree},
 button(label){const button=nodes(this.tree).find(n=>n.type==='button'&&text(n)===label);assert.ok(button,'Missing '+label);return button},
 close(){for(const effect of effects)effect?.cleanup?.()},
 };
 h.document=Object.assign(new EventTarget(),{visibilityState:'visible',createElement:()=>({textContent:''})});
 Object.defineProperty(globalThis,'document',{configurable:true,value:h.document});Object.defineProperty(globalThis,'window',{configurable:true,value:{matchMedia:()=>({matches:false})}});Object.defineProperty(globalThis,'navigator',{configurable:true,value:{geolocation:{getCurrentPosition(resolve,reject){h.locate=resolve;h.reject=reject}}}});Object.defineProperty(globalThis,'fetch',{configurable:true,value:(...args)=>{h.calls.push(args);throw new Error('A sample map must not request a routing API')}});globalThis.mapRoute=h;
 t.after(()=>{h.close();delete globalThis.mapRoute;for(const[name,value]of Object.entries(original)){if(value)Object.defineProperty(globalThis,name,value);else delete globalThis[name]}});return h;
}
test('sample delivery draws its line without requesting location or a routing service', async t => {
 const h=harness(t);h.props={};h.render();await tick();h.render();await tick();
 assert.equal(h.calls.length,0);assert.equal(h.locate,undefined);
 assert.equal(h.lines.length,2);assert.equal(h.lines[1].options.color,'#E00D42');
 assert.equal(h.lines[1].options.dashArray,undefined);assert.equal(h.lines[1].options.smoothFactor,0);assert.ok(h.lines[1].points.length>2);
 assert.match(text(h.tree),/m along mapped streets/);
 assert.match(text(h.tree),/Illustration only/);assert.match(text(h.tree),/Sample rider/);
 assert.doesNotMatch(text(h.tree),/Origin hub|Geoapify|Preview road route/);
});
test('switching between a saved stop and the sample replaces and releases its map',async t=>{
 const h=harness(t);let removed=0;h.instance.remove=()=>{removed++;h.lines=[]};
 h.render();await tick();h.render();assert.equal(h.lines.length,0);
 h.button('Try sample route').props.onClick();h.render();await tick();h.render();
 assert.equal(h.lines.length,2);assert.equal(removed,1);
 h.button('Show saved stop').props.onClick();h.render();await tick();h.render();
 assert.equal(h.lines.length,0);assert.equal(removed,2);assert.equal(h.locate,undefined);
 h.close();assert.equal(removed,3);
});
