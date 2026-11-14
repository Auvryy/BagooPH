import type { ShippingCalculation } from '@/types';

type ShopRoute = { shop_id: number; shop_name: string; shipping_quote?: ShippingCalculation };

export default function CheckoutRoutePreview({ shops, ready, pending, selfPickup = false }: {
    shops: ShopRoute[]; ready: boolean; pending: boolean; selfPickup?: boolean;
}) {
    if (pending) return <p role="status" className="text-xs text-slate-600">Checking delivery route…</p>;
    if (!ready) return null;
    const planned = shops.filter(shop => shop.shipping_quote?.route?.length);
    if (!planned.length) return null;

    return <details className="rounded-lg border border-rose-200 bg-rose-50/40 p-3">
        <summary className="cursor-pointer text-xs font-semibold text-[#C20836] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#E00D42]">
            Planned delivery route{planned.length > 1 ? ` · ${planned.length} shops` : ''}
        </summary>
        <div className="mt-3 space-y-3">
            {planned.map(shop => <div key={shop.shop_id}>
                <div className="mb-2 flex flex-wrap items-center gap-2 text-xs">
                    <span className="font-semibold text-slate-900">{shop.shop_name}</span>
                    <span className="rounded-full bg-rose-100 px-2 py-0.5 font-medium text-[#9F1239]">
                        {shop.shipping_quote?.same_hub_bypass ? 'Local hub route' : 'Via Mother Hub'}
                    </span>
                </div>
                <ol aria-label={`Planned route for ${shop.shop_name}`} className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-700">
                    <li>Seller pickup</li>
                    {shop.shipping_quote?.route?.map(hub => <li key={hub.id} className="min-w-0 break-words">
                        <span aria-hidden="true" className="mr-2 text-[#C20836]">→</span>{hub.name}
                    </li>)}
                    <li><span aria-hidden="true" className="mr-2 text-[#C20836]">→</span>{selfPickup ? 'Hub collection' : 'Your address'}</li>
                </ol>
                {shop.shipping_quote?.eta && (
                    <div className="mt-2 space-y-1 text-xs text-slate-700">
                        <p>
                            <span className="font-semibold text-slate-900">Estimated arrival:</span>{' '}
                            {shop.shipping_quote.eta.label}
                            {shop.shipping_quote.eta.cutoff_applied && (
                                <span className="ml-1.5 text-[11px] text-amber-800">
                                    (After 16:00 PHT cutoff — dispatch begins next window)
                                </span>
                            )}
                        </p>
                        {shop.shipping_quote.eta.pause_notice && (
                            <p role="status" className="rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-[11px] font-medium text-amber-900">
                                {shop.shipping_quote.eta.pause_notice}
                            </p>
                        )}
                    </div>
                )}
                {shop.shipping_quote?.coverage?.coverage_status === 'no_available_rider' && (
                    <p className="mt-1.5 rounded-md border border-zinc-200 bg-white px-2.5 py-1.5 text-[11px] text-zinc-700">
                        {shop.shipping_quote.coverage.message}
                    </p>
                )}
            </div>)}
            <p className="text-xs text-slate-600">Confirmed when you place your order. Tracking updates after handover scans.</p>
        </div>
    </details>;
}
