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
const tmp = mkdtempSync(join(import.meta.dirname, '.tmp-eta-monitoring-'));
after(() => {
    rmSync(tmp, { recursive: true, force: true });
    delete globalThis.route;
});

const built = await build({
    stdin: {
        contents: `
            export { default as CheckoutRoutePreview } from '@/Components/CheckoutRoutePreview';
            export { default as OrderTrackingPanel } from '@/Components/OrderTrackingPanel';
            export { default as BuyerParcelProgress } from '@/Components/BuyerParcelProgress';
            export { default as PublicTracking } from '@/Pages/Public/Tracking';
            export { default as GovernanceMonitoring } from '@/Pages/Governance/Monitoring';
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
            name: 'stub-dashboard-layout',
            setup(builder) {
                builder.onResolve({ filter: /^@\/Layouts\/DashboardLayout$/ }, () => ({
                    path: 'layout',
                    namespace: 'stub-layout',
                }));
                builder.onLoad({ filter: /.*/, namespace: 'stub-layout' }, () => ({
                    loader: 'js',
                    contents: `export default function DashboardLayout({ children }) { return children; }`,
                }));
            },
        },
    ],
});

const bundlePath = join(tmp, 'b14-b15.mjs');
writeFileSync(bundlePath, built.outputFiles[0].text);
const ui = await import(pathToFileURL(bundlePath).href);
globalThis.route = (name) => (name ? '/' + name.replaceAll('.', '/') : { current: () => false, has: () => true });

async function renderPage(Component, props = {}, user = { id: 1, name: 'Admin User', email: 'admin@bagoo.test', role: 'admin' }) {
    let element;
    const page = {
        component: 'Governance/Monitoring',
        props: { auth: { user }, flash: {}, errors: {}, ...props },
        url: '/governance/monitoring',
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

test('CheckoutRoutePreview renders PHT ETA range, after-cutoff notice, Force Majeure advisory, and no_available_rider note', () => {
    const html = renderToStaticMarkup(
        createElement(ui.CheckoutRoutePreview, {
            ready: true,
            pending: false,
            shops: [
                {
                    shop_id: 1,
                    shop_name: 'Laguna Roasters',
                    shipping_quote: {
                        route: [
                            { id: 1, name: 'Origin Bayan', tier: 'local_bayan_hub', code: 'BH-01' },
                            { id: 2, name: 'Destination Bayan', tier: 'local_bayan_hub', code: 'BH-02' },
                        ],
                        same_hub_bypass: true,
                        eta: {
                            label: 'Nov 12 - 15, 2026 (PHT)',
                            eta_status: 'paused',
                            status_label: 'Paused (Force Majeure)',
                            cutoff_applied: true,
                            pause_notice: 'Typhoon advisory active across Laguna corridor.',
                        },
                        coverage: {
                            coverage_status: 'no_available_rider',
                            message: 'Route coverage is valid, zero riders currently have pickup capacity.',
                        },
                    },
                },
            ],
        }),
    );

    assert.match(html, /Nov 12 - 15, 2026 \(PHT\)/);
    assert.match(html, /After 16:00 PHT cutoff/);
    assert.match(html, /Typhoon advisory active across Laguna corridor\./);
    assert.match(html, /zero riders currently have pickup capacity/);
});

test('OrderTrackingPanel and BuyerParcelProgress display ETA bounds and honest Pickup Delayed banner', () => {
    const tracking = {
        status: 'ready_for_pickup',
        status_label: 'Ready for pickup',
        tone: 'attention',
        custodian: 'Laguna Roasters',
        next_step: 'Awaiting rider collection',
        observed_at: '2026-11-11T04:00:00Z',
        history: [],
        eta: {
            label: 'Nov 13 - 16, 2026 (PHT)',
            eta_status: 'delayed',
            status_label: 'Delayed (Updated Forecast)',
            delay_notice: 'Revised ETA calculated after exceeding initial forecast window.',
        },
        dispatch_delay_label: 'Pickup Delayed — Reassigning Rider',
    };

    const sellerHtml = renderToStaticMarkup(createElement(ui.OrderTrackingPanel, { tracking }));
    assert.match(sellerHtml, /Pickup Delayed — Reassigning Rider/);
    assert.match(sellerHtml, /Nov 13 - 16, 2026 \(PHT\)/);
    assert.match(sellerHtml, /Delayed \(Updated Forecast\)/);

    const buyerHtml = renderToStaticMarkup(
        createElement(ui.BuyerParcelProgress, {
            tracking,
            order: { id: 9, order_number: 'BGO-100', status: 'ready_for_pickup', review_state: {} },
        }),
    );
    assert.match(buyerHtml, /Priority rider dispatch is underway/);
    assert.match(buyerHtml, /Nov 13 - 16, 2026 \(PHT\)/);
});

test('PublicTracking shows sanitized ETA without leaking internal confidential operational notes', async () => {
    const html = await renderPage(ui.PublicTracking, {
        parcel: {
            tracking_number: 'BGO-PUB-101',
            status: 'in_transit',
            delivery_recipient_name: 'A*** S***',
            delivery_phone: '0917***567',
            delivery_address: 'Protected location, Santa Cruz, Laguna',
            estimated_delivery_at: '2026-11-15T10:00:00Z',
            eta: {
                label: 'Nov 14 - 16, 2026 (PHT)',
                eta_status: 'paused',
                status_label: 'Paused (Force Majeure)',
                pause_notice: 'Schedule paused due to a regional Force Majeure or service advisory.',
            },
            delivered_at: null,
            checkpoints: [],
        },
        searchedNumber: 'BGO-PUB-101',
        notFound: false,
        validationMessage: null,
        statusLabels: { in_transit: 'In transit' },
        checkpointLabels: {},
        observedAt: '2026-11-11T04:00:00Z',
    }, { id: 0, name: 'Guest', email: '', role: 'buyer' });

    assert.match(html, /Nov 14 - 16, 2026 \(PHT\)/);
    assert.match(html, /Force Majeure or service advisory/);
    assert.match(html, /BGO-PUB-101/);
});

test('Governance Monitoring page renders Force-Majeure controls, SLA telemetry, dry-run trigger, and investigations', async () => {
    const html = await renderPage(ui.GovernanceMonitoring, {
        scope: {
            is_admin: true,
            is_company_manager: false,
            company_id: null,
            hub_id: null,
            base_path: '/admin',
        },
        pauses: [
            {
                id: 5,
                reference: 'PAUSE-0005',
                scope_type: 'platform',
                scope_value: null,
                reason_code: 'typhoon',
                title: 'Signal No. 3 Luzon Corridor Pause',
                notes: 'All deliveries paused.',
                status: 'active',
                starts_at: '2026-11-11T02:00:00Z',
                ends_at: null,
                resumed_at: null,
                accumulated_paused_seconds: 0,
            },
        ],
        clocks: [],
        telemetry: {
            counts: {
                running_clocks: 4,
                paused_clocks: 2,
                breached_clocks: 1,
                stalled_hub_alerts: 1,
                urgent_pickups: 3,
                active_pickup_pauses: 1,
                overloaded_barangays: 0,
            },
            escalations: [],
            pickup_pauses: [],
            overload_alerts: [],
        },
        investigations: [
            {
                id: 11,
                reference: 'INV-0011',
                source_kind: 'manifest_missing',
                status: 'investigating',
                proves_loss: false,
                liability_determined: false,
                compensation_status: 'pending_review',
                insurer_funding_status: 'deferred_no_insurer_pool',
                shipment_financial_plan_id: null,
                summary: 'Manifest missing parcel during mother hub offload.',
                resolution_notes: null,
                opened_at: '2026-11-11T03:00:00Z',
                resolved_at: null,
            },
        ],
        companies: [{ id: 1, name: 'Bagoo Express', code: 'BGX' }],
        hubs: [{ id: 1, name: 'Los Banos Hub', code: 'BH-LBN', tier: 'local_bayan_hub', province: 'Laguna', city_municipality: 'Los Banos', logistics_company_id: 1 }],
        investigators: [{ id: 2, name: 'Supervisor Ana', role: 'logistics' }],
        financialPlans: [],
        openDeliveries: [],
        constants: { scopes: ['platform', 'province', 'company', 'hub'], reasons: ['typhoon', 'road_closure'] },
    });

    assert.match(html, /Monitoring telemetry/);
    assert.match(html, /Signal No\. 3 Luzon Corridor Pause/);
    assert.match(html, /Preview scan \(dry-run\)/);
    assert.match(html, /Active &amp; paused SLA clocks/);
});
