import type { ShippingCalculation } from '@/types';

type ShopRoute = { shop_id: number; shop_name: string; shipping_quote?: ShippingCalculation };

export default function CheckoutRoutePreview({ shops, ready, pending, selfPickup = false }: {
    shops: ShopRoute[]; ready: boolean; pending: boolean; selfPickup?: boolean;
}) {
    if (pending) return <p role="status" className="text-[13px] text-ink-600">Checking delivery route…</p>;
    if (!ready) return null;
    const planned = shops.filter(shop => shop.shipping_quote?.route?.length);
    if (!planned.length) return null;

    return <details className="group rounded-control bg-surface-100 px-4 py-3">
        <summary className="cursor-pointer text-[13px] font-medium text-ink-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-500">
            Planned delivery route{planned.length > 1 ? ` · ${planned.length} shops` : ''}
        </summary>
        <div className="mt-3 space-y-3">
            {planned.map(shop => <div key={shop.shop_id}>
                <div className="mb-2 flex flex-wrap items-center gap-2 text-[13px]">
                    <span className="font-semibold text-ink-900">{shop.shop_name}</span>
                    <span className="rounded-chip bg-white px-1.5 py-0.5 text-xs font-medium text-ink-600">
                        {shop.shipping_quote?.same_hub_bypass ? 'Local hub route' : 'Via Mother Hub'}
                    </span>
                </div>
                <ol aria-label={`Planned route for ${shop.shop_name}`} className="flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px] text-ink-700">
                    <li>Seller pickup</li>
                    {shop.shipping_quote?.route?.map(hub => <li key={hub.id} className="min-w-0 break-words">
                        <span aria-hidden="true" className="mr-2 text-ink-400">→</span>{hub.name}
                    </li>)}
                    <li><span aria-hidden="true" className="mr-2 text-ink-400">→</span>{selfPickup ? 'Hub collection' : 'Your address'}</li>
                </ol>
                {shop.shipping_quote?.eta && (
                    <div className="mt-2 space-y-1 text-[13px] text-ink-700">
                        <p>
                            <span className="font-medium text-ink-900">Estimated arrival:</span>{' '}
                            {shop.shipping_quote.eta.label}
                            {shop.shipping_quote.eta.cutoff_applied && (
                                <span className="ml-1.5 text-xs text-warning">
                                    (After 16:00 PHT cutoff — dispatch begins next window)
                                </span>
                            )}
                        </p>
                        {shop.shipping_quote.eta.pause_notice && (
                            <p role="status" className="rounded-chip bg-warning-soft px-2.5 py-1.5 text-xs font-medium text-warning">
                                {shop.shipping_quote.eta.pause_notice}
                            </p>
                        )}
                    </div>
                )}
                {shop.shipping_quote?.coverage?.coverage_status === 'no_available_rider' && (
                    <p className="mt-1.5 rounded-chip bg-white px-2.5 py-1.5 text-xs text-ink-700">
                        {shop.shipping_quote.coverage.message}
                    </p>
                )}
            </div>)}
            <p className="text-xs text-ink-600">Confirmed when you place your order. Tracking updates as your parcel is scanned.</p>
        </div>
    </details>;
}
