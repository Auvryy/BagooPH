import React, { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { Clock } from 'lucide-react';
import { Product } from '@/types';
import { saleCountdown } from '@/utils/saleTime';
import { invalidateCatalogue } from '@/utils/catalogueRuntime';

interface Props { product: Product }
const date = (iso: string) => new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Manila', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }).format(new Date(iso));

export default function ProductSaleOffer({ product }: Props) {
    const offer = product.sale_offer;
    const [now, setNow] = useState(() => offer?.server_now ? Date.parse(offer.server_now) : Date.now());
    const refreshing = useRef(false);
    const boundary = useRef('');
    const active = offer?.active;

    useEffect(() => {
        if (!active) return;
        const origin = Date.now();
        const server = offer?.server_now ? Date.parse(offer.server_now) : origin;
        const refresh = () => {
            if (refreshing.current || document.visibilityState === 'hidden') return;
            refreshing.current = true;
            invalidateCatalogue();
            router.reload({ only: ['product', 'variations', 'purchaseAllowance', 'cartQuantities'], onFinish: () => { refreshing.current = false; } });
        };
        const timer = window.setInterval(() => {
            const current = server + Date.now() - origin;
            setNow(current);
            if (Date.parse(active.ends_at) <= current && boundary.current !== active.ends_at) {
                boundary.current = active.ends_at;
                refresh();
            }
        }, 1000);
        const poll = window.setInterval(refresh, 60000);
        const visibility = () => { if (document.visibilityState === 'visible') refresh(); };
        document.addEventListener('visibilitychange', visibility);
        return () => { window.clearInterval(timer); window.clearInterval(poll); document.removeEventListener('visibilitychange', visibility); };
    }, [active?.id, active?.ends_at, offer?.server_now]);

    if (!active) return null;

    return <section aria-label="Product sale" className="my-4 space-y-3 rounded-lg border border-rose-200 bg-rose-50/60 p-4">
        <div>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-sm font-bold text-[#C20836]">{active.name}</h3>
                <span className="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-700">
                    <Clock className="h-3.5 w-3.5" aria-hidden="true" />
                    Ends in {saleCountdown(active.ends_at, now)}
                </span>
            </div>
            <p className="mt-1 text-xs text-slate-600">Ends {date(active.ends_at)} (Asia/Manila).</p>
        </div>
    </section>;
}
