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
const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-buyer-address-'));
after(() => { rmSync(temporary, { recursive: true, force: true }); delete globalThis.route; delete globalThis.addressHarness; });
globalThis.route = name => name ? `/${name.replaceAll('.', '/')}` : { current: () => false, has: () => true };
const bundle = await build({ stdin: { contents: `export { default as Profile } from '@/Pages/Buyer/Profile';
 export { default as Editor } from '@/Components/BuyerAddressEditor'; export { default as Checkout } from '@/Pages/Checkout/Index';
 export { default as Recipient } from '@/Components/OrderRecipientSummary'; export * from '@/utils/buyerAddresses'; export * from '@/utils/addressLocations';
 export { personalNameError } from '@/utils/registrationValidation';`, resolveDir: root, loader: 'tsx' },
 bundle: true, packages: 'external', platform: 'node', format: 'esm', jsx: 'automatic', write: false, loader: { '.css': 'empty' },
 plugins: [{ name: 'address-dialog-content', setup(builder) {
  builder.onResolve({ filter: /^@headlessui\/react$/ }, args => args.importer.endsWith('/BuyerAddressEditor.tsx') ? { path: 'dialog', namespace: 'address-dialog' } : undefined);
  builder.onLoad({ filter: /.*/, namespace: 'address-dialog' }, () => ({ contents: `import {createElement} from 'react';
   export const Dialog = ({open,children}) => open ? createElement('div',{role:'dialog'},children) : null;
   export const DialogPanel = ({children,className}) => createElement('div',{className},children);
   export const DialogTitle = ({children}) => createElement('h2',{},children);`, resolveDir: root }));
  builder.onResolve({ filter: /hooks\/usePurchaseQuote$/ }, args => args.importer.includes('/Pages/') ? { path: 'quote', namespace: 'address-quote' } : undefined);
  builder.onLoad({ filter: /.*/, namespace: 'address-quote' }, () => ({ contents: 'export default (input,quote,offers=[])=>({quote,offers,current:true,pending:false,failure:"",ready:true});' }));
 } }] });
const pagesPath = join(temporary, 'pages.mjs'); writeFileSync(pagesPath, bundle.outputFiles[0].text);
const ui = await import(pathToFileURL(pagesPath).href);
const hook = await build({ entryPoints: [resolve(root, 'resources/js/Components/BuyerAddressLocationFields.tsx')], bundle: true, packages: 'external', platform: 'node', format: 'esm', jsx: 'automatic', write: false,
 plugins: [{ name: 'controlled-location-effects', setup(builder) {
  builder.onResolve({ filter: /^react$/ }, () => ({ path: 'react', namespace: 'address-hooks' }));
  builder.onLoad({ filter: /.*/, namespace: 'address-hooks' }, () => ({ contents: `export const useState=x=>globalThis.addressHarness.state(x); export const useRef=x=>globalThis.addressHarness.ref(x); export const useEffect=(fn,deps)=>globalThis.addressHarness.effect(fn,deps);` }));
  builder.onResolve({ filter: /^axios$/ }, () => ({ path: 'axios', namespace: 'address-http' }));
  builder.onLoad({ filter: /.*/, namespace: 'address-http' }, () => ({ contents: `export default {get:(...args)=>globalThis.addressHarness.request(...args),isCancel:e=>e?.code==='ERR_CANCELED',isAxiosError:e=>e?.isAxiosError===true};` }));
 } }] });
const hookPath = join(temporary, 'locations.mjs'); writeFileSync(hookPath, hook.outputFiles[0].text);

const user = { id: 1, name: 'Registered Buyer', email: 'buyer@bagoo.test', role: 'buyer', status: 'active', kyc_status: 'approved' };
const address = { id: 7, user_id: 1, recipient_name: 'Saved Delivery Recipient', phone: '+639171234567', region: '1300000000', region_name: 'National Capital Region (NCR)', province: 'Metro Manila', city: 'City of Pasig', barangay: 'San Antonio', street: '24 Mabini Street', postal_code: '1600', type: 'Home', is_default: true };
async function render(Component, props) {
 let view;
 const result = await createInertiaApp({ page: { component: 'Buyer/AddressTest', props: { auth: { user }, flash: {}, cartCount: 0, ...props }, url: '/buyer/profile?tab=addresses', version: null },
  resolve: () => Component, setup: ({ App, props }) => { view = createElement(App, props); return view; }, render: () => renderToStaticMarkup(view) });
 return result.body;
}
const profile = { user, addresses: [address], wallet: { available: false }, orders: { data: [] }, orderCounts: { all: 0 }, ordersCount: 0, initialTab: 'addresses', canUsePortal: true, addressBookLimit: 10 };
function button(html, label) { return [...html.matchAll(/<button\b[^>]*>[\s\S]*?<\/button>/g)].map(x => x[0]).find(x => x.includes(label)); }

test('address book shows actual saved details, postal code, region, and a clear default deletion rule', async () => {
 const html = await render(ui.Profile, profile);
 for (const value of ['24 Mabini Street', '1600', 'National Capital Region (NCR)', 'Saved Delivery Recipient']) assert.ok(html.includes(value));
 assert.match(html, /1 of 10 saved/); assert.match(html, /choose another saved address as default first/);
 assert.match(button(html, 'Default address cannot be deleted'), /disabled=""/);
});
test('ten addresses disable adding while keeping existing address editing available', async () => {
 const html = await render(ui.Profile, { ...profile, addresses: Array.from({ length: 10 }, (_, index) => ({ ...address, id: index + 1, is_default: index === 0 })) });
 assert.match(button(html, 'Add New Address'), /disabled=""/);
 assert.doesNotMatch(button(html, '>Edit<'), /disabled=""/);
 assert.match(html, /Your address book is full/);
});
test('pending buyers can read addresses but the controls cannot change them', async () => {
 const html = await render(ui.Profile, { ...profile, canUsePortal: false });
 assert.match(button(html, 'Add New Address'), /disabled=""/);
 assert.match(button(html, '>Edit<'), /disabled=""/);
 assert.match(html, /active and approved/);
});
test('address editor uses the stored recipient, bounded fields and the existing buyer dialog style', async () => {
 const html = await render(ui.Editor, { user, address, count: 1, limit: 10, onClose() {} });
 assert.match(html, /role="dialog"/); assert.match(html, /rounded-3xl/); assert.match(html, /bg-\[#E00D42\]/);
 assert.match(html, /id="address-recipient-name"[^>]*minLength="2"[^>]*maxLength="100"[^>]*value="Saved Delivery Recipient"/i);
 assert.doesNotMatch(html, /id="address-recipient-name"[^>]*(?:readonly|disabled)/i);
 assert.match(html, /id="address-street"[^>]*maxLength="500"/i);
 assert.match(html, /id="address-postal-code"[^>]*maxLength="4"/i);
 assert.match(html, /does not change your account name/);
 for (const field of ['region', 'province', 'city', 'barangay']) assert.match(html, new RegExp(`id="address-${field}"[^>]*required=""`));
});
const quote = { items: [{id:10, quantity:1, unit_price:250, subtotal:250}], subtotal: 250, shipping_fee: 50, doorstep_shipping_fee: 50, discount: 0, total: 300, voucher: null, redemptions: [],
 shops: [{ shop_id: 1, shop_name: 'Bagoo Store', subtotal: 250, shipping: 50, doorstep_shipping: 50, discount: 0, total: 300 }] };
const items = [{ id: 10, quantity: 1, unit_price: 250, available_for_purchase: true, product: { id: 5, name: 'Everyday Bag', price: 250, stock: 10, images: [], shop: { id: 1, name: 'Bagoo Store' } } }];
test('checkout preselects the actual default recipient and permits delivery contact edits', async () => {
 const other = { ...address, id: 9, recipient_name: 'Default Office Recipient' };
 const html = await render(ui.Checkout, { cart: { id: 1 }, items, quote, subtotal: 250, shippingFee: 50, total: 300, user, kycStatus: 'approved', checkoutToken: 'confirmation', addresses: [{ ...address, is_default: false }, other], defaultAddressId: 9 });
 assert.match(html, /id="checkout-recipient-name"[^>]*value="Default Office Recipient"/);
 assert.doesNotMatch(html, /id="checkout-recipient-name"[^>]*(?:readonly|disabled)/i);
 assert.match(html, /<option value="9" selected=""/);
});
test('waybill recipient uses the complete immutable order details and ignores current profile contact data', () => {
 const html = renderToStaticMarkup(createElement(ui.Recipient, { order: { recipient_name: 'Original Recipient', recipient_phone: '+639171234567', shipping_address: '84 Original Office Street', destination_barangay: 'San Antonio', shipping_city: 'City of Pasig', shipping_province: 'Metro Manila', shipping_postal_code: '1600', buyer: { name: 'Changed Account Name', phone: '+639181234567', address: '15 Future Street' } } }));
 for (const value of ['Original Recipient', '+639171234567', '84 Original Office Street', 'San Antonio', 'City of Pasig', 'Metro Manila', '1600']) assert.ok(html.includes(value));
 assert.doesNotMatch(html, /Changed Account|15 Future|639181234567/);
});
test('missing historical waybill details remain unrecorded instead of using profile data or invented values', () => {
 const html = renderToStaticMarkup(createElement(ui.Recipient, { order: { buyer: { name: 'Current Name', phone: '09170000000' } } }));
 assert.match(html, /Recipient not recorded/); assert.match(html, /Phone not recorded/); assert.match(html, /Delivery address not recorded/);
 assert.doesNotMatch(html, /Current Name|09170000000|Metro Manila/);
});
test('address names accept apostrophes and accents but reject numbers, hidden text and invalid lengths', () => {
 const base = { ...address, phone: '09171234567' };
 assert.deepEqual(ui.buyerAddressErrors({ ...base, recipient_name: "Andréa O'Neil" }), {});
 assert.deepEqual(ui.buyerAddressErrors({ ...base, recipient_name: 'A'.repeat(100) }), {});
 for (const name of ['A', ' ', 'Name 123', 'A'.repeat(101), 'And\u200brea', '<b>Andrea</b>']) assert.ok(ui.buyerAddressErrors({ ...base, recipient_name: name }).recipient_name);
 assert.ok(ui.personalNameError('A'.repeat(51), 'First name'), 'Registration parts retain their existing fifty-character maximum.');
});
test('address client feedback covers every required field, mobile formats and optional postal format', () => {
 for (const field of ['recipient_name', 'phone', 'region', 'province', 'city', 'barangay', 'street']) assert.ok(ui.buyerAddressErrors({ ...address, [field]: '' })[field]);
 for (const phone of ['09171234567', '+639171234567']) assert.equal(ui.buyerAddressErrors({ ...address, phone }).phone, undefined);
 for (const phone of ['0917123', '091712345678', '+14155552671']) assert.ok(ui.buyerAddressErrors({ ...address, phone }).phone);
 assert.equal(ui.buyerAddressErrors({ ...address, postal_code: '' }).postal_code, undefined);
 assert.ok(ui.buyerAddressErrors({ ...address, postal_code: '123' }).postal_code);
});
test('changing ancestors clears dependent location fields while selecting the same value preserves them', () => {
 const values = { region: address.region, province: address.province, city: address.city, barangay: address.barangay };
 assert.deepEqual(ui.changeAddressLocation(values, 'region', '0700000000'), { region: '0700000000', province: '', city: '', barangay: '' });
 assert.deepEqual(ui.changeAddressLocation(values, 'province', 'Cebu'), { ...values, province: 'Cebu', city: '', barangay: '' });
 assert.deepEqual(ui.changeAddressLocation(values, 'city', 'City of Makati'), { ...values, city: 'City of Makati', barangay: '' });
 assert.equal(ui.changeAddressLocation(values, 'region', values.region), values);
});

const tick = () => new Promise(resolve => setImmediate(resolve));
function nodes(node) { return !node || typeof node !== 'object' ? [] : [node, ...[node.props?.children].flat(Infinity).flatMap(nodes)]; }
function harness(Component, values, t) {
 const h = { slots: [], cursor: 0, effects: [], requests: [], loading: [], values, tree: null,
  state(initial) { const i=this.cursor++; this.slots[i] ??= {value:typeof initial==='function'?initial():initial}; return [this.slots[i].value, value=>{this.slots[i].value=typeof value==='function'?value(this.slots[i].value):value;}]; },
  ref(initial) { const i=this.cursor++; return this.slots[i] ??= {current:initial}; },
  effect(fn,deps) { const i=this.cursor++; const prior=this.slots[i]; if(!prior||deps.some((x,j)=>!Object.is(x,prior.deps[j])))this.effects.push(()=>{prior?.cleanup?.();this.slots[i]={deps,cleanup:fn()};}); },
  request(url, options) { let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no;});this.requests.push({url,options,resolve,reject});return promise; },
  render() { this.cursor=0;globalThis.addressHarness=this;this.tree=Component({values:this.values,onChange:next=>{this.values=next;},onLoadingChange:value=>this.loading.push(value)});this.effects.splice(0).forEach(fn=>fn());return this.tree; },
  select(field,value) { nodes(this.tree).find(x=>x.type==='select'&&x.props.name===field).props.onChange({target:{value}});this.render(); },
 };
 t.after(()=>h.slots.forEach(x=>x?.cleanup?.()));return h;
}
const choices = { regions: [{value:'1300000000',label:'NCR'},{value:'0700000000',label:'Region VII'}], provinces:[],cities:[],barangays:[],resolved:{region:'',province:'',city:''} };
test('a slow old location response cannot replace the current region choices', async t => {
 const {default:Component}=await import(pathToFileURL(hookPath).href+'?stale');
 const h=harness(Component,{region:'1300000000',province:'Metro Manila',city:'City of Makati',barangay:'Bel-Air'},t);h.render();
 const first=h.requests[0];h.select('region','0700000000');assert.equal(first.options.signal.aborted,true);assert.equal(h.values.city,'');assert.equal(h.values.barangay,'');
 h.requests[1].resolve({data:{...choices,provinces:[{value:'Cebu',label:'Cebu'}],resolved:{region:'0700000000',province:'',city:''}}});await tick();h.render();
 first.resolve({data:{...choices,provinces:[{value:'Metro Manila',label:'Metro Manila'}],cities:[{value:'City of Makati',label:'City of Makati'}],resolved:{region:'1300000000',province:'Metro Manila',city:'City of Makati'}}});await tick();h.render();
 const province=nodes(h.tree).find(x=>x.type==='select'&&x.props.name==='province');
 assert.ok(nodes(province).some(x=>x.type==='option'&&x.props.value==='Cebu'));assert.ok(!nodes(province).some(x=>x.props?.value==='Metro Manila'));
 assert.equal(h.values.region,'0700000000');assert.equal(h.loading.at(-1),false);
});
test('failed location reads keep saving unavailable and retry without losing entered location data', async t => {
 const {default:Component}=await import(pathToFileURL(hookPath).href+'?retry');
 const h=harness(Component,{region:'',province:'',city:'',barangay:''},t);h.render();
 assert.equal(h.requests[0].options.timeout,10000);
 h.requests[0].reject(Object.assign(new Error('request timed out'),{isAxiosError:true,code:'ECONNABORTED'}));await tick();h.render();
 assert.equal(h.loading.at(-1),true);
 assert.ok(nodes(h.tree).some(x=>x.props?.role==='alert'));
 const retry=nodes(h.tree).find(x=>x.type==='button'&&x.props.children==='Try again');retry.props.onClick();h.render();assert.equal(h.requests.length,2);
 h.requests[1].resolve({data:choices});await tick();h.render();assert.equal(h.loading.at(-1),false);
 assert.deepEqual(h.values,{region:'',province:'',city:'',barangay:''});
 assert.deepEqual(Object.keys(h.requests[1].options.params).sort(),['city','province','region']);
});
