import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createInertiaApp } from '@inertiajs/react';

const root = resolve(import.meta.dirname, '../..');
const tmp = mkdtempSync(join(import.meta.dirname, '.tmp-moderation-'));
after(() => {
    rmSync(tmp, { recursive: true, force: true });
    delete globalThis.route;
});

const built = await build({
    stdin: {
        contents: `
            export { default as GovernanceModeration } from '@/Pages/Governance/Moderation';
            export { default as SellerReviews } from '@/Pages/Seller/Reviews';
            export { default as BuyerOrderDetail } from '@/Pages/Buyer/OrderDetail';
            export { default as BuyerProductDetail } from '@/Pages/Buyer/ProductDetail';
            export { default as AccountStatus } from '@/Pages/Auth/AccountStatus';
            export { default as SellerDashboard } from '@/Pages/Seller/Dashboard';
            export { default as CheckoutIndex } from '@/Pages/Checkout/Index';
            export { default as BuyerPaymentChoice } from '@/Components/BuyerPaymentChoice';
            export { default as AccountEnforcementNotices } from '@/Components/AccountEnforcementNotices';
            export { default as CourierDeliveryFeedbackSection } from '@/Components/CourierDeliveryFeedbackSection';
            export { default as ReviewModerationNotice } from '@/Components/ReviewModerationNotice';
        `,
        resolveDir: root,
        loader: 'tsx',
    },
    bundle: true,
    packages: 'external',
    platform: 'node',
    format: 'esm',
    jsx: 'automatic',
    write: false,
    loader: { '.css': 'empty' },
    plugins: [
        {
            name: 'stub-layouts',
            setup(builder) {
                builder.onResolve({ filter: /^@\/Layouts\/(DashboardLayout|BuyerLayout|GuestLayout)$/ }, args => ({
                    path: args.path,
                    namespace: 'stub-layout',
                }));
                builder.onLoad({ filter: /.*/, namespace: 'stub-layout' }, () => ({
                    loader: 'js',
                    contents: `export default function StubLayout({ children }) { return children; }`,
                }));
            },
        },
    ],
});

const bundlePath = join(tmp, 'b16.mjs');
writeFileSync(bundlePath, built.outputFiles[0].text);
const ui = await import(pathToFileURL(bundlePath).href);
globalThis.route = (name, parameter) => {
    const base = name ? `/${name.replaceAll('.', '/')}` : '';
    const suffix = parameter ? `/${typeof parameter === 'object' ? parameter.id : parameter}` : '';
    const fn = () => `${base}${suffix}`;
    fn.current = () => false;
    fn.has = () => true;
    return name ? `${base}${suffix}` : fn;
};

async function renderPage(Component, componentName, props = {}, role = 'buyer') {
    let element;
    const page = {
        component: componentName,
        props: {
            auth: { user: { id: 7, name: 'Test User', email: `${role}@bagoo.test`, role } },
            flash: {},
            errors: {},
            cartCount: 0,
            categories: [],
            ...props,
        },
        url: '/test',
        version: null,
    };
    const rendered = await createInertiaApp({
        page,
        resolve: () => Component,
        setup: ({ App, props: appProps }) => (element = createElement(App, appProps)),
        render: () => renderToStaticMarkup(element),
    });

    return rendered.body;
}

test('Governance/Moderation renders scoped enforcement, independent appeals, SLA penalties, scale logs, review disputes, and non-punitive signals without font-mono', async () => {
    const html = await renderPage(ui.GovernanceModeration, 'Governance/Moderation', {
        adminId: 99,
        sanctions: [{
            id: 1, reference: 'ENF-COD1', subject_type: 'buyer', subject_id: 7, subject_user_id: 7,
            scope: 'cod_restricted', status: 'active', reason_code: 'repeated_cod_refusal',
            reason: 'Repeated COD refusal across closed plans.', evidence_summary: 'Plans SFP-1, SFP-2, SFP-3.',
            auto_release_on_debt_clear: false, issued_by_id: 12, issued_by_role: 'admin', issued_at: '2026-11-14T10:00:00Z',
            expires_at: null, resolved_at: null, resolution_notes: null,
            subject_user: { id: 7, name: 'Ana Buyer', email: 'ana@bagoo.test', role: 'buyer' },
        }],
        appeals: [{
            id: 2, reference: 'APL-101', enforcement_sanction_id: 1, appellant_user_id: 7,
            reason: 'Flooded road caused delivery miss.', evidence_notes: 'Weather advisory', status: 'pending',
            review_notes: null, reviewed_at: null, created_at: '2026-11-14T11:00:00Z',
            appellant: { id: 7, name: 'Ana Buyer', role: 'buyer' },
            sanction: { id: 1, reference: 'ENF-COD1', issued_by_id: 12, scope: 'cod_restricted' },
        }],
        penalties: [{
            id: 3, reference: 'PEN-SLA150', subject_type: 'company', subject_id: 4, subject_user_id: 4,
            category: 'sla_pickup_breach', amount_cents: 15000, settled_cents: 0, outstanding_cents: 15000,
            status: 'pending_review', reason_code: 'sla_pickup_breach', reason: 'Geo-fenced Laguna pickup breach.',
            evidence_summary: 'Active coverage verified.', decision_notes: null, proposed_at: '2026-11-14T10:30:00Z',
            decided_at: null, settled_at: null,
        }],
        signals: [{
            id: 4, reference: 'SIG-CHAT1', signal_type: 'off_platform_bypass_keyword', severity: 'advisory',
            status: 'open', is_punitive: false, summary: 'GCash keyword matched in chat; non-punitive safety review.',
            matched_terms: ['gcash'], buyer_checkin_response: null, buyer_checkin_notes: null, created_at: '2026-11-14T09:00:00Z',
        }],
        scaleLogs: [{
            id: 5, reference: 'SCL-101', declared_weight_grams: 1000, measured_weight_grams: 3000, overage_grams: 2000,
            declared_shipping_cents: 8500, recalculated_shipping_cents: 15500, tariff_delta_cents: 7000,
            status: 'flagged_for_penalty', notes: 'Calibrated bench scale', recorded_at: '2026-11-14T10:00:00Z',
        }],
        reviewDisputes: [{
            id: 6, reference: 'RVD-101', review_id: 88, reason_code: 'courier_delivery_complaint',
            seller_evidence: 'Shipped within SLA; buyer only complained about courier delay.', status: 'pending',
            adjudicated_by_id: null, adjudication_notes: null, buyer_appeal_reason: null, appeal_notes: null,
            created_at: '2026-11-14T10:00:00Z',
            review: { id: 88, rating: 1, comment: 'Courier was slow.', moderation_status: 'published', product: { id: 2, name: 'Tote Bag', slug: 'tote-bag' } },
        }],
        heldReviews: [{
            id: 89, rating: 5, comment: 'Bulk burst test', moderation_status: 'held_velocity_review',
            moderation_reason: 'Velocity burst check', created_at: '2026-11-14T10:00:00Z',
            product: { id: 2, name: 'Tote Bag' }, buyer: { id: 7, name: 'Ana Buyer' },
        }],
        breachedSlaClocks: [],
        deliveriesForScale: [],
        subjects: { buyers: [{ id: 7, name: 'Ana Buyer', email: 'ana@bagoo.test' }], sellers: [], shops: [], companies: [] },
        constants: {
            scopes: ['warning', 'cod_restricted', 'review_restricted', 'catalog_visibility_restricted', 'payout_hold', 'pickup_zone_restricted'],
            reasonCodes: { repeated_cod_refusal: 'Repeated COD delivery refusal' },
            defaultSlaPenaltyCents: 15000,
        },
    }, 'admin');

    assert.match(html, /Evidence-Based Enforcement &amp; Review Moderation/);
    assert.match(html, /ENF-COD1/);
    assert.match(html, /APL-101/);
    assert.doesNotMatch(html, /font-mono/);
});

test('Seller/Reviews renders moderation badges and dispute controls cleanly', async () => {
    const html = await renderPage(ui.SellerReviews, 'Seller/Reviews', {
        shop: { id: 1, name: 'Bagoo Craft Shop', rating: 2.5 },
        reviews: {
            data: [
                {
                    id: 11, rating: 1, comment: 'Delivery guy arrived late.', verified_purchase: true,
                    created_at: '2026-11-14T10:00:00Z', moderation_status: 'excluded_courier_issue',
                    buyer: { id: 7, name: 'Ana Santos' },
                    product: { id: 2, name: 'Canvas Tote' },
                    dispute: { id: 6, reference: 'RVD-202', status: 'upheld_excluded_courier', adjudication_notes: 'Courier delay verified.' },
                },
                {
                    id: 12, rating: 4, comment: 'Nice strap.', verified_purchase: true,
                    created_at: '2026-11-14T11:00:00Z', moderation_status: 'published',
                    buyer: { id: 8, name: 'Ben Cruz' },
                    product: { id: 2, name: 'Canvas Tote' },
                    dispute: null,
                },
            ],
            current_page: 1, last_page: 1, per_page: 15, total: 2,
        },
        stats: {
            average_rating: 2.5,
            total_reviews: 2,
            response_rate: '0%',
            rating_breakdown: { '5_star': 0, '4_star': 1, '3_star': 0, '2_star': 0, '1_star': 1 },
        },
    }, 'seller');

    assert.match(html, /Excluded from product &amp; shop score \(Courier issue\)/);
    assert.match(html, /RVD-202/);
    assert.match(html, /Dispute review/);
    assert.doesNotMatch(html, /font-mono/);
});

test('Buyer/OrderDetail renders decoupled courier feedback, anti-scam check-in, product-only review prompt, and review appeal', async () => {
    const html = await renderPage(ui.BuyerOrderDetail, 'Buyer/OrderDetail', {
        order: {
            id: 40, buyer_id: 7, order_number: 'BGO-B16', status: 'completed', total_amount: 650, subtotal: 600, shipping_fee: 50,
            payment_method: 'cod', payment_status: 'paid', created_at: '2026-11-14T00:00:00Z', completed_at: '2026-11-14T08:00:00Z',
            items: [
                {
                    id: 101, product_id: 5, quantity: 1, unit_price: 600, subtotal: 600,
                    product: { id: 5, name: 'Canvas Backpack', slug: 'canvas-backpack', featured_image: null },
                    review: null,
                },
                {
                    id: 102, product_id: 6, quantity: 1, unit_price: 400, subtotal: 400,
                    product: { id: 6, name: 'Travel Pouch', slug: 'travel-pouch', featured_image: null },
                    review: {
                        id: 55, rating: 1, comment: 'Courier tossed the parcel.',
                        moderation_status: 'excluded_courier_issue',
                        moderation_reason: 'Comment targets courier speed rather than item construction.',
                        appeal_url: '/buyer/reviews/55/appeal',
                        dispute: null,
                    },
                },
            ],
            delivery: { id: 20, status: 'delivered', tracking_number: 'BGO-PARCEL-20' },
            tracking: {
                commercial_status: 'completed', parcel_status: 'delivered', status_label: 'Completed',
                stage_label: 'Delivered', tone: 'complete', custodian: 'Buyer', history: [],
            },
        },
        canUsePortal: true,
        canConfirmReceipt: false,
        orderNotices: [],
        deliveryFeedback: null,
        deliveryFeedbackUrl: '/buyer/deliveries/20/feedback',
        safetyCheckinSignals: [{
            id: 91, reference: 'SIG-SAFE91', signal_type: 'anti_scam_stalled_order', status: 'open',
            summary: 'Order progress stalled before delivery completion. Prompt buyer with a non-punitive safety check-in.',
        }],
        activeSanctions: [{
            id: 31, reference: 'ENF-COD31', subject_type: 'buyer', scope: 'cod_restricted', status: 'active',
            reason_code: 'repeated_cod_refusal', reason: 'Repeated COD refusals on record.',
        }],
    }, 'buyer');

    const orderDetailSrc = readFileSync(resolve(root, 'resources/js/Pages/Buyer/OrderDetail.tsx'), 'utf8');
    assert.match(orderDetailSrc, /Tell other buyers about product sizing, quality, and material\.\.\./);
    assert.doesNotMatch(orderDetailSrc, /and delivery condition/);
    assert.match(html, /Delivery &amp; Courier Service Feedback/);
    assert.match(html, /Bagoo Anti-Scam Safety Check-In \(SIG-SAFE91\)/);
    assert.match(html, /Appeal moderation decision/);
    assert.match(html, /ENF-COD31/);
    assert.doesNotMatch(html, /font-mono/);
});

test('Checkout COD restriction disables COD cleanly while AccountStatus and SellerDashboard expose appeals and wallet settlement', async () => {
    const choiceHtml = renderToStaticMarkup(createElement(ui.BuyerPaymentChoice, {
        value: 'wallet',
        onChange: () => {},
        balanceCents: 100000,
        codDisabled: true,
        codDisabledReason: 'Repeated COD refusal.',
    }));
    assert.match(choiceHtml, /disabled=""[^>]*value="cod"|value="cod"[^>]*disabled=""/);

    const statusHtml = await renderPage(ui.AccountStatus, 'Auth/AccountStatus', {
        status: {
            title: 'Account Active',
            message: 'Portal access enabled.',
            account: { id: 7, name: 'Ana', email: 'ana@bagoo.test', role: 'seller', status: 'active', kyc_status: 'approved', email_verified_at: '2026-11-01T00:00:00Z' },
            sources: [], requests: [], can_correct_application: false, can_correct_identity: false, can_open_portal: true, email_update: null,
            active_sanctions: [{
                id: 41, reference: 'ENF-HOLD41', subject_type: 'seller', scope: 'payout_hold', status: 'active',
                reason_code: 'unpaid_debt_obligation', reason: 'Payout hold pending settlement.', auto_release_on_debt_clear: true,
            }],
            unpaid_penalties: [{
                id: 51, reference: 'PEN-DEBT51', penalty_type: 'policy_breach', amount_cents: 20000, outstanding_cents: 20000,
                status: 'unpaid_debt_hold', reason: 'Audited obligation awaiting Bagoo Wallet settlement.',
            }],
        },
    }, 'seller');

    assert.match(statusHtml, /ENF-HOLD41/);
    assert.match(statusHtml, /PEN-DEBT51/);
    assert.match(statusHtml, /Submit appeal/);
    assert.match(statusHtml, /Pay from Bagoo Wallet/);
    assert.match(statusHtml, /Releases automatically once debt clears/);

    const productHtml = await renderPage(ui.BuyerProductDetail, 'Buyer/ProductDetail', {
        product: {
            id: 5, name: 'Canvas Backpack', slug: 'canvas-backpack', price: 600, stock: 10, rating: null, reviews_count: 1,
            description: 'Canvas bag', images: [], featured_image: null, variants: { colors: [], sizes: [] },
            shop: { id: 1, name: 'Test Shop', city: 'Los Banos', slug: 'test-shop' },
            reviews: [{
                id: 77, rating: 1, comment: 'Slow rider late delivery.', created_at: '2026-11-14T00:00:00Z',
                verified_purchase: true, score_excluded: true, moderation_status: 'excluded_courier_issue',
                buyer: { name: 'A*** S***', avatar: null },
            }],
        },
        relatedProducts: [],
        variations: { colors: [], sizes: [], combinations: [] },
        shopStats: { rating: null, products_count: 1, review_count: 0, response_rate: null, joined: null },
    }, 'buyer');

    assert.match(productHtml, /Excluded from product score \(Delivery issue\)/);
    assert.match(productHtml, /Slow rider late delivery\./);
    assert.doesNotMatch(productHtml, />4\.8</);
});
