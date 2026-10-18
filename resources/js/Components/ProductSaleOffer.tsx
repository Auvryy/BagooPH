import React, { useEffect, useRef, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { Bell, BellOff, Clock } from 'lucide-react';
import { PageProps, Product } from '@/types';
import { saleCountdown } from '@/utils/saleTime';
import { invalidateCatalogue } from '@/utils/catalogueRuntime';

export interface SaleReminderState { sale_id: number; subscribed: boolean; notified: boolean; delivered?: boolean; discarded?: boolean }
interface Props { product: Product; reminder?: SaleReminderState | null }
const date = (iso: string) => new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Manila', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }).format(new Date(iso));

export default function ProductSaleOffer({ product, reminder = null }: Props) {
    const offer = product.sale_offer;
    const { auth } = usePage<PageProps>().props;
    const [now, setNow] = useState(() => offer?.server_now ? Date.parse(offer.server_now) : Date.now());
    const [busy, setBusy] = useState(false);
    const [feedback, setFeedback] = useState('');
    const [subscribed, setSubscribed] = useState(reminder?.subscribed ?? false);
    const refreshing = useRef(false);
    const boundary = useRef('');
    useEffect(() => { setSubscribed(reminder?.subscribed ?? false); }, [reminder?.sale_id, reminder?.subscribed]);
    const active = offer?.active;
    const upcoming = offer?.upcoming;
    useEffect(() => {
        if (!active && !upcoming) return;
        const origin = Date.now();
        const server = offer?.server_now ? Date.parse(offer.server_now) : origin;
        const refresh = () => {
            if (refreshing.current || document.visibilityState === 'hidden') return;
            refreshing.current = true;
            invalidateCatalogue();
            router.reload({ only: ['product', 'variations', 'saleReminder', 'purchaseAllowance', 'cartQuantities'], onFinish: () => { refreshing.current = false; } });
        };
        const timer = window.setInterval(() => {
            const current = server + Date.now() - origin;
            setNow(current);
            const due = [active?.ends_at, upcoming?.starts_at].filter(Boolean).find(value => Date.parse(value!) <= current);
            if (due && boundary.current !== due) { boundary.current = due; refresh(); }
        }, 1000);
        const poll = window.setInterval(refresh, 60000);
        const visibility = () => { if (document.visibilityState === 'visible') refresh(); };
        document.addEventListener('visibilitychange', visibility);
        return () => { window.clearInterval(timer); window.clearInterval(poll); document.removeEventListener('visibilitychange', visibility); };
    }, [active?.id, active?.ends_at, upcoming?.id, upcoming?.starts_at, offer?.server_now]);
    const toggle = () => {
        if (!upcoming || busy) return;
        setBusy(true); setFeedback('');
        const next = !subscribed;
        const options = { preserveScroll: true, onSuccess: () => { setSubscribed(next); setFeedback(next ? 'Reminder saved. Check your notifications before the sale.' : 'Reminder removed.'); },
            onError: (errors: Record<string, string>) => setFeedback(String(errors.sale_reminder || 'The reminder could not be saved. Try again.')),
            onFinish: () => setBusy(false) };
        const url = route(next ? 'buyer.sale-reminders.store' : 'buyer.sale-reminders.destroy', upcoming.id);
        if (next) router.post(url, {}, options); else router.delete(url, options);
    };
    if (!active && !upcoming) return null;
    return <section aria-label="Product sale" className="my-4 space-y-3 rounded-lg border border-rose-200 bg-rose-50/60 p-4">
        {active && <div><div className="flex flex-wrap items-center justify-between gap-2"><h3 className="text-sm font-bold text-[#C20836]">{active.name}</h3><span className="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-700"><Clock className="h-3.5 w-3.5" aria-hidden="true" />Ends in {saleCountdown(active.ends_at, now)}</span></div><p className="mt-1 text-xs text-slate-600">Ends {date(active.ends_at)} (Asia/Manila).</p>{active.kind === 'flash' && <p className="mt-2 text-sm text-slate-800">{active.remaining_quantity} promotional units remain. Extra units use the store sale or regular price, shown separately at checkout.</p>}</div>}
        {upcoming && <div className={active ? 'border-t border-rose-200 pt-3' : ''}><p className="text-sm font-semibold text-slate-900">{upcoming.name}</p><p className="mt-1 text-xs text-slate-600">Starts {date(upcoming.starts_at)} (Asia/Manila). Set a reminder for a notification up to 10 minutes before the slot.</p>{auth.user?.role === 'buyer' ? <button type="button" onClick={toggle} disabled={busy || (!!reminder?.delivered && !subscribed)} className="mt-3 inline-flex min-h-10 items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-800 hover:border-[#E00D42] disabled:opacity-50">{subscribed ? <BellOff className="h-4 w-4" /> : <Bell className="h-4 w-4" />}{busy ? 'Saving…' : subscribed ? 'Remove reminder' : reminder?.delivered ? 'Reminder sent' : 'Remind me'}</button> : !auth.user ? <Link href={route('login')} className="mt-3 inline-block text-sm font-semibold text-[#E00D42] underline">Sign in for a reminder</Link> : null}</div>}
        {feedback && <p role="status" className="text-xs text-slate-700">{feedback}</p>}
    </section>;
}
