import { Popover, PopoverButton, PopoverPanel } from '@headlessui/react';
import { Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { Bell, RefreshCw, X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import useNotificationAction from '@/hooks/useNotificationAction';
import type { PageProps } from '@/types';

export interface Notice {
    id: string;
    type: string;
    data: { title?: string; body?: string; order_number?: string; hub_name?: string; hub_address?: string; operating_hours?: string; expires_at?: string };
    read_at: string | null;
    created_at: string;
    href: string | null;
}

export const noticeDate = (value: string) => new Date(value).toLocaleString('en-PH', { timeZone: 'Asia/Manila', dateStyle: 'medium', timeStyle: 'short' });
export { openNotice } from '@/hooks/useNotificationAction';
type Summary = NonNullable<PageProps['notificationSummary']>;

function NotificationPreview({ open, close }: { open: boolean; close: () => void }) {
    const { auth, notificationSummary } = usePage<PageProps>().props;
    const { component } = usePage();
    const buyer = auth.user?.role === 'buyer';
    const titleId = useId();
    const [notices, setNotices] = useState<Notice[]>([]);
    const [summary, setSummary] = useState<Summary>(notificationSummary ?? { available: true, unread: 0 });
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [retry, setRetry] = useState(0);
    const loaded = useRef(false);
    const action = useNotificationAction(close);
    const radius = buyer ? 'rounded-xl' : 'rounded-[8px]';
    const count = summary.unread ?? 0;
    const countAvailable = summary.available && summary.unread !== null;

    useEffect(() => { if (notificationSummary) setSummary(notificationSummary); }, [notificationSummary]);
    useEffect(() => {
        if (!open) return;
        const controller = new AbortController();
        loaded.current = false;
        setLoading(true);
        setError('');
        setNotices([]);
        (async () => {
            try {
                const response = await axios.get<{ notices: Notice[]; summary: Summary }>('/notifications/preview', {
                    signal: controller.signal, headers: { Accept: 'application/json' },
                });
                if (controller.signal.aborted) return;
                loaded.current = true;
                setNotices(response.data.notices);
                setSummary(response.data.summary);
            } catch {
                if (!controller.signal.aborted) setError('Notifications could not load. Please try again.');
            } finally { if (!controller.signal.aborted) setLoading(false); }
        })();
        return () => { controller.abort(); loaded.current = false; };
    }, [open, retry, auth.user?.id, component]);
    useEffect(() => {
        if (!open || loading || !loaded.current) return;
        const unread = notices.filter(notice => !notice.read_at).map(notice => notice.id);
        if (!unread.length) return;
        const controller = new AbortController();
        // Acknowledge after the returned rows have rendered, not while their preview is still loading.
        (async () => {
            try {
                const saved = await axios.patch<{ summary: Summary }>('/notifications/read-displayed', { ids: unread }, {
                    signal: controller.signal, headers: { Accept: 'application/json' },
                });
                if (controller.signal.aborted) return;
                setSummary(saved.data.summary);
                if (component === 'Notifications/Index') router.reload({ only: ['notificationSummary', 'notices'] });
            } catch {
                if (!controller.signal.aborted) setError('Your updates loaded, but we could not mark them as read. Try again to refresh their read status.');
            }
        })();
        return () => controller.abort();
    }, [open, loading, notices, component]);

    const label = !countAvailable ? 'Notifications temporarily unavailable' : `Notifications${count ? `, ${count} unread` : ''}`;
    return <>
        <PopoverButton aria-label={label} title={label} className={`relative inline-flex h-10 w-10 shrink-0 items-center justify-center ${radius} border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42]`}>
            <Bell className="h-[18px] w-[18px]" aria-hidden="true" />
            {count > 0 && countAvailable && <span aria-hidden="true" className="absolute -right-1 -top-1 flex min-h-4 min-w-4 items-center justify-center rounded-full bg-[#E00D42] px-1 text-[10px] font-semibold text-white">{count > 99 ? '99+' : count}</span>}
        </PopoverButton>
        <PopoverPanel as="section" anchor={{ to: 'bottom end', gap: 12, padding: 16 }} focus aria-labelledby={titleId} className={`z-[70] flex max-h-[min(70dvh,var(--anchor-max-height))] w-[min(24rem,calc(100vw-2rem))] flex-col overflow-hidden border border-slate-300 bg-white font-sans text-slate-900 shadow-xl ${buyer ? 'rounded-2xl' : 'rounded-[8px]'}`}>
            <div className="flex shrink-0 items-center justify-between gap-3 border-b border-slate-300 p-4">
                <div><h2 id={titleId} className="text-base font-bold">Notifications</h2><p className="mt-1 text-xs text-slate-500">{countAvailable ? `Recent updates · ${count} unread` : 'Unread count temporarily unavailable'}</p></div>
                <button type="button" onClick={close} aria-label="Close notifications" className={`${radius} p-2 hover:bg-slate-100 focus-visible:ring-2 focus-visible:ring-[#E00D42]`}><X className="h-4 w-4" aria-hidden="true" /></button>
            </div>
            <div className="min-h-0 overflow-y-auto">
                {loading && <p role="status" className="p-5 text-sm text-slate-600">Loading your updates...</p>}
                {(error || action.error) && <div role="alert" className={`m-4 ${radius} border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900`}>
                    <p>{error || action.error}</p>
                    {error && <button type="button" disabled={loading} onClick={() => setRetry(value => value + 1)} className="mt-2 inline-flex min-h-10 items-center gap-2 font-semibold underline underline-offset-4 disabled:opacity-50"><RefreshCw className="h-4 w-4" aria-hidden="true" />Try again</button>}
                </div>}
                {!loading && !error && notices.length === 0 && <p className="p-6 text-center text-sm text-slate-600">No notifications yet. {buyer ? 'Your order and account updates will appear here.' : 'Your account and work updates will appear here.'}</p>}
                <ul className="divide-y divide-slate-200">{notices.map(notice => <li key={notice.id}>
                    <button type="button" disabled={action.reading !== null} onClick={() => action.open(notice.id)} className={`w-full break-words p-4 text-left transition-colors hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[#E00D42] disabled:opacity-50 ${!notice.read_at ? 'bg-rose-50/70' : 'bg-white'}`}>
                        <span className="block text-sm font-bold">{!notice.read_at && <span aria-label="New update" className="mr-2 inline-block h-2 w-2 rounded-full bg-[#E00D42]" />}{notice.data.title ?? 'Account update'}</span>
                        <span className="mt-1 block text-xs leading-relaxed text-slate-600">{notice.data.body}</span>
                        <time dateTime={notice.created_at} className="mt-2 block text-[11px] text-slate-500">{noticeDate(notice.created_at)}</time>
                    </button>
                </li>)}</ul>
            </div>
            <Link href="/notifications" onClick={() => close()} className="block shrink-0 border-t border-slate-300 p-4 text-center text-sm font-bold text-[#C20836] hover:bg-rose-50 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[#E00D42]">View all notifications</Link>
        </PopoverPanel>
    </>;
}

export default function NotificationMenu() {
    return <Popover className="relative shrink-0">{({ open, close }) => <NotificationPreview open={open} close={close} />}</Popover>;
}
