import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { buildSync } from 'esbuild';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createInertiaApp } from '@inertiajs/react';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-voucher-campaigns-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = buildSync({ stdin: { contents: `export * from '@/Components/Vouchers/campaignTypes'; export {default as Seller} from '@/Pages/Seller/Vouchers'; export {default as Admin} from '@/Pages/Admin/Vouchers'; export {default as History} from '@/Pages/Admin/VoucherHistory';`, resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' }, bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', loader: {'.css': 'empty'}, write: false });
const path = join(temporary, 'campaigns.mjs'); writeFileSync(path, bundle.outputFiles[0].text);
const { previewPromotion, manilaInput, campaignBenefit, Seller, Admin, History } = await import(pathToFileURL(path).href);
globalThis.route = name => name ? '/' + name.replaceAll('.', '/') : {current: () => false};
after(() => delete globalThis.route);
const terms = {code: 'SHOP_TEST', name: 'Shop savings', description: '', discount_type: 'percent', value_cents: 0, percentage_bps: 1250, minimum_cents: 50000, maximum_cents: 10000, budget_cents: 500000, global_limit: 50, per_buyer_limit: 1, starts_at: '2026-10-11T00:00:00Z', ends_at: '2026-10-18T16:00:00Z'};
const campaign = {id: 1, code: 'SHOP_TEST', name: 'Shop savings', issuer_type: 'shop', shop_name: 'Bagoo Crafts', state: 'active', version: 2, has_draft: false, current_revision_id: 1, draft_terms: null, published_terms: terms, terms, approved_budget_cents: 500000, balance: {redemption_count: 2, committed_cents: 20000, spent_cents: 0, released_cents: 0}, legacy_used_count: null, suspended_at: null};
const stats = {campaigns: 16, active: 16, saves: 31, redemptions: 25, legacy_uses: 0, cancelled_allocations: 2, budget_cents: 800000, committed_cents: 150000, spent_cents: 20000, released_cents: 10000, remaining_cents: 630000};
async function render(Component, name, props, role) {
 let view; const result = await createInertiaApp({ page: { component: name, props: { auth: {user: {id: 1, name: 'Ana Santos', role}}, flash: {}, errors: {}, ...props}, url: role === 'admin' ? '/admin/vouchers' : '/seller/vouchers', version: null }, resolve: () => Component, setup: ({App, props}) => {view = createElement(App, props);return view;}, render: () => renderToStaticMarkup(view) }); return result.body;
}
test('seller percentage preview shows a real capped cost and original gross share', () => {
 const result = previewPromotion({discount_type:'percent', discount_value:'12.50', max_discount:'100'}, '1000');
 assert.deepEqual(result, {basis:100000, sellerShare:90000, discount:10000, netSeller:80000});
 assert.equal(previewPromotion({discount_type:'fixed', discount_value:'0.01', max_discount:'0.01'}, '0.05').netSeller, 3);
});
test('shipping preview never reduces products and uses its finite cap', () => {
 const result=previewPromotion({discount_type:'free_shipping', discount_value:'0', max_discount:'20'}, '500');
 assert.equal(result.discount, 2000); assert.equal(result.basis, 50000);
 assert.match(campaignBenefit({...terms,discount_type:'free_shipping',maximum_cents:2000}), /Shipping discount up to ₱20.00/);
});
test('datetime controls and campaign dates use Philippine time', () => {
 assert.equal(manilaInput('2026-10-11T16:30:00Z'), '2026-10-12T00:30');
 assert.equal(manilaInput('invalid'), '');
});
test('seller reports use supplied full-query statistics while retaining shop controls', async () => {
 const html=await render(Seller, 'Seller/Vouchers', {campaigns:{data:[campaign],current_page:1,last_page:2},stats,shop:{id:1,name:'Bagoo Crafts'}}, 'seller');
 assert.match(html, /16 campaigns across all pages/); assert.match(html,/31/); assert.match(html,/25/);
 assert.match(html, /Edit draft/); assert.match(html,/Pause/); assert.doesNotMatch(html,/Suspend shop voucher|Clear suspension|Claims/);
 assert.match(html,/Oct 19, 2026/); assert.match(html,/12.5% off, up to ₱100.00/);
});
test('admin can suspend shop campaigns and only edit its own platform offers', async () => {
 const html=await render(Admin,'Admin/Vouchers',{campaigns:{data:[campaign,{...campaign,id:2,code:'BAGOO_TEST',issuer_type:'platform',shop_name:null,state:'paused'}],current_page:1,last_page:1},stats},'admin');
 assert.match(html,/New Bagoo voucher/); assert.match(html,/Suspend/); assert.match(html,/Resume/);
 assert.equal((html.match(/>Edit draft</g)||[]).length,1);
 assert.match(html,/Committed promotion cost/); assert.match(html,/Settled promotion expense/);
});
test('ended campaigns retain history and have no new publication action', async () => {
 const html=await render(Seller,'Seller/Vouchers',{campaigns:{data:[{...campaign,state:'archived'}],current_page:1,last_page:1},stats,shop:{id:1}},'seller');
 assert.match(html,/Original history retained/); assert.match(html,/>History</); assert.doesNotMatch(html,/>Edit draft<|>Pause<|>Resume<|>Publish draft<|>End</);
});
test('empty campaigns have a centered useful message instead of invented offers', async () => {
 const html=await render(Seller,'Seller/Vouchers',{campaigns:{data:[],current_page:1,last_page:1},stats:{...stats,campaigns:0,active:0,saves:0,redemptions:0},shop:{id:1}},'seller');
 assert.match(html,/min-h-64[^"]*items-center[^"]*justify-center/); assert.match(html,/No voucher campaigns yet/); assert.doesNotMatch(html,/FREESHIP|Revenue lift/);
});
test('history records the reason and exact old and new funding ceilings', async () => {
 const html=await render(History,'Admin/VoucherHistory',{campaign,backUrl:'/admin/vouchers',history:{data:[{id:1,action:'budget_increased',actor_name:'Ana Santos',reason:'Extend the dated campaign.',voucher_revision_id:1,budget_increase_cents:10000,before_state:{approved_budget_cents:500000},after_state:{approved_budget_cents:510000},created_at:'2026-10-11T16:00:00Z'}],current_page:1,last_page:1}},'admin');
 assert.match(html,/Extend the dated campaign/); assert.match(html,/₱5,000.00 to ₱5,100.00/); assert.doesNotMatch(html,/<form/);
});
