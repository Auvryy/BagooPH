import {after, test} from 'node:test';
import assert from 'node:assert/strict';
import {mkdtempSync,rmSync,writeFileSync} from 'node:fs';
import {join,resolve} from 'node:path';
import {pathToFileURL} from 'node:url';
import {build} from 'esbuild';
import {createElement} from 'react';
import {renderToStaticMarkup} from 'react-dom/server';
import {createInertiaApp} from '@inertiajs/react';

const root=resolve(import.meta.dirname,'../..');
const temporary=mkdtempSync(join(import.meta.dirname,'.tmp-portal-consistency-'));
after(()=>{rmSync(temporary,{recursive:true,force:true});delete globalThis.portalConsistencyHarness;delete globalThis.route;});
const result=await build({stdin:{contents:`export {default as Sidebar} from '@/Components/AdminSidebarNavigation'; export {default as Dashboard} from '@/Layouts/DashboardLayout'; export {default as Sales} from '@/Pages/Seller/Dashboard'; export {default as Names} from '@/Components/PersonalNameFields';`,resolveDir:root,loader:'tsx'},bundle:true,packages:'external',platform:'node',format:'esm',jsx:'automatic',write:false,loader:{'.css':'empty'},plugins:[{name:'controlled-portal-hooks',setup(b){
 b.onResolve({filter:/^react$/},args=>/\/(AdminSidebarNavigation|PersonalNameFields)\.tsx$/.test(args.importer)?{path:'hooks',namespace:'portal-hooks'}:undefined);
 b.onLoad({filter:/.*/,namespace:'portal-hooks'},()=>({contents:`import * as React from 'react'; export * from 'react'; export const useId=()=>globalThis.portalConsistencyHarness?'navigation-section':React.useId(); export const useState=value=>globalThis.portalConsistencyHarness?globalThis.portalConsistencyHarness.state(value):React.useState(value); export const useEffect=(fn,deps)=>{if(globalThis.portalConsistencyHarness)globalThis.portalConsistencyHarness.effects.push(fn);else React.useEffect(fn,deps);};`,resolveDir:root}));
}}]});
const path=join(temporary,'portal.mjs');writeFileSync(path,result.outputFiles[0].text);const ui=await import(pathToFileURL(path).href);
globalThis.route=name=>name?'/'+name.replaceAll('.','/'):{current:()=>false,has:()=>true};
const icon=()=>createElement('svg');
const nodes=n=>!n||typeof n!=='object'?[]:[n,...[n.props?.children].flat(Infinity).flatMap(nodes)];
const text=n=>Array.isArray(n)?n.map(text).join(''):n&&typeof n==='object'?text(n.props?.children):String(n??'');
const seller={id:7,name:'Seller',email:'seller@bagoo.test',role:'seller'};
async function render(Component,props={},user=seller){let element;const page={component:user.role==='admin'?'Admin/Users':'Seller/Dashboard',props:{auth:{user},flash:{},...props},url:'/dashboard',version:null};const result=await createInertiaApp({page,resolve:()=>Component,setup:({App,props})=>(element=createElement(App,props)),render:()=>renderToStaticMarkup(element)});return result.body;}

test('admin sections retain all destinations and open the active group with keyboard accessible buttons',async()=>{
 const names=['Dashboard','KYC Queue','Shop reviews','Users','Review requests','Products','Voucher campaigns','Advertisements','Logistics','Delivery exceptions','Buyer returns','Handover issues','COD cash','Seller proceeds','Wallet credits','Virtual proceeds','Financial oversight','Governance history','Resource activity'];
 const items=names.map((name,index)=>({name,href:'/destination/'+index,icon,current:name==='Users',attention:name==='Products'}));
 const html=await render(ui.Sidebar,{items},{...seller,role:'admin'});
 assert.equal((html.match(/href="\/destination\//g)??[]).length,names.length);
 for(const title of ['Accounts &amp; reviews','Marketplace','Deliveries &amp; returns','Finance','Governance'])assert.ok(html.includes(title));
 assert.match(html,/<button[^>]*aria-expanded="true"[^>]*>[\s\S]*?Accounts &amp; reviews/);
 assert.match(html,/aria-current="page"[^>]*[\s\S]*?Users/);
 assert.match(html,/<div[^>]*hidden=""[^>]*role="group" aria-label="Marketplace"/);
 assert.match(html,/h-2 w-2 rounded-full bg-\[#E00D42\]/);
});

test('admin sections really collapse and reopen through their controls',()=>{
 const h={slots:[],index:0,effects:[],state(value){const i=this.index++;this.slots[i]??=typeof value==='function'?value():value;return[this.slots[i],next=>{this.slots[i]=typeof next==='function'?next(this.slots[i]):next;}];}};
 globalThis.portalConsistencyHarness=h;
 const root=ui.Sidebar({items:[{name:'Products',href:'/products',icon,current:true}]});
 const section=nodes(root).find(n=>n.props?.section?.key==='marketplace');
 h.index=0;let tree=section.type(section.props);assert.equal(nodes(tree).find(n=>n.type==='button').props['aria-expanded'],true);
 nodes(tree).find(n=>n.type==='button').props.onClick();h.index=0;tree=section.type(section.props);
 assert.equal(nodes(tree).find(n=>n.props?.role==='group').props.hidden,true);
 nodes(tree).find(n=>n.type==='button').props.onClick();h.index=0;tree=section.type(section.props);
 assert.equal(nodes(tree).find(n=>n.props?.role==='group').props.hidden,false);
 delete globalThis.portalConsistencyHarness;
});

test('middle initials add one period on blur and allow editing a second letter after focus',()=>{
 const h={state:value=>[value,()=>{}]};globalThis.portalConsistencyHarness=h;
 let value='md';const properties=()=>({values:{first_name:'Ana',middle_initial:value,last_name:'Santos'},onChange:(field,next)=>{if(field==='middle_initial')value=next;}});
 let tree=ui.Names(properties());let input=nodes(tree).find(n=>n.type==='input'&&n.props.name==='middle_initial');
 input.props.onBlur();assert.equal(value,'MD.');tree=ui.Names(properties());input=nodes(tree).find(n=>n.type==='input'&&n.props.name==='middle_initial');
 input.props.onFocus();assert.equal(value,'MD');assert.equal(input.props.maxLength,3);
 value='M1';tree=ui.Names(properties());nodes(tree).find(n=>n.type==='input'&&n.props.name==='middle_initial').props.onBlur();assert.equal(value,'M1');
 delete globalThis.portalConsistencyHarness;
});

const stats={totalProducts:1,lowStockCount:0,outOfStockCount:0,completedGrossSales:500,completedUnits:1,completedOrderCount:1,averageCompletedOrderValue:500,estimatedSellerShare:450,openOrderValue:0,openUnits:0,openOrderCount:0,pendingPackCount:0,readyPickupCount:0,shippedCount:0,completedCount:1,returnCount:0};
const sales=Array.from({length:30},(_,i)=>({date:'Oct '+(i+1),revenue:i===29?500:0,units:i===29?1:0}));
const props={shop:{id:1,name:'Bagoo Shop'},stats,dailySales:sales,salesPeriod:30,recentOrders:[],topProducts:[]};
test('seller chart renders actual sales and usable period choices instead of hiding data beyond seven days',async()=>{
 const html=await render(ui.Sales,props);
 assert.match(html,/Sales chart period/);for(const days of [7,30,90])assert.ok(html.includes('Last '+days+' days'));
 assert.doesNotMatch(html,/No completed sales in this period/);
 assert.match(html,/role="img"[^>]*tabindex="0"/i);
 assert.match(html,/Philippine dates/);
 assert.ok((html.match(/class="uppercase tracking-wider"/g)??[]).length<=8);
 assert.doesNotMatch(html,/NaN/);
 assert.doesNotMatch(html,/Sales chart activity|aria-pressed=|>Awaiting confirmation<|>Completed sales<\/button>/);
});
test('delivered orders display their own dated activity and cannot be presented as completed income',async()=>{
 const html=await render(ui.Sales,{...props,stats:{...stats,completedGrossSales:0,completedOrderCount:0},dailySales:sales.map(x=>({...x,revenue:0,units:0})),dailyDeliveries:sales});
 assert.match(html,/Delivered order value/);assert.match(html,/They count as completed sales after the buyer confirms receipt/);
 assert.doesNotMatch(html,/No delivered orders awaiting confirmation/);
 assert.doesNotMatch(html,/Selected date completed sales/);
 assert.doesNotMatch(html,/Sales chart activity|aria-pressed=/);
});
test('seller chart returns to completed sales when a selected period contains both types of activity',async()=>{
 const html=await render(ui.Sales,{...props,dailyDeliveries:sales});
 assert.match(html,/<h3[^>]*>Completed sales<\/h3>/);
 assert.doesNotMatch(html,/Delivered order value|Awaiting confirmation/);
});
test('empty seller chart has no mode controls and centers its message beneath the header',async()=>{
 const html=await render(ui.Sales,{...props,stats:{...stats,completedGrossSales:0,completedOrderCount:0},dailySales:sales.map(x=>({...x,revenue:0,units:0}))});
 assert.match(html,/No completed sales in this period/);
 assert.match(html,/relative flex flex-1 flex-col justify-center/);
 assert.doesNotMatch(html,/Sales chart activity|>Awaiting confirmation<|>Completed sales<\/button>/);
});
test('seller navigation uses red attention dots without numeric badges',async()=>{
 const html=await render(ui.Dashboard,{title:'Seller',children:null,sidebarAttention:{messages:true,products:true,vouchers:true},unreadMessagesCount:12});
 assert.match(html,/Unread messages/);assert.match(html,/Drafts, restricted listings or low stock/);
 assert.equal((html.match(/ring-2 ring-white/g)??[]).length,3);
 assert.doesNotMatch(html,/>12<|>12 unread<|animate-pulse/);
});
