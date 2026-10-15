import { Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { Bell, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
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

export function openNotice(id: string, onFinish?: () => void) {
    router.patch(`/notifications/${id}/read`, { open: true }, { preserveScroll: true, onFinish });
}

export default function BuyerNotificationMenu() {
    const { notificationSummary } = usePage<PageProps>().props;
    const [open, setOpen] = useState(false);
    const [notices, setNotices] = useState<Notice[]>([]);
    const [count, setCount] = useState(notificationSummary?.unread ?? 0);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [opening, setOpening] = useState<string | null>(null);
    const container = useRef<HTMLDivElement>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    const heading = useRef<HTMLHeadingElement>(null);
    useEffect(() => setCount(notificationSummary?.unread ?? 0), [notificationSummary]);
    useEffect(() => {
        if (!open) return;
        const close = (event: MouseEvent) => {
            if (!container.current?.contains(event.target as Node)) setOpen(false);
        };
        const escape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') { setOpen(false); trigger.current?.focus(); }
        };
        document.addEventListener('mousedown', close);
        document.addEventListener('keydown', escape);
        return () => { document.removeEventListener('mousedown', close); document.removeEventListener('keydown', escape); };
    }, [open]);
    useEffect(() => {
        if (!open) return;
        const controller = new AbortController();
        setLoading(true); setError(''); setNotices([]);
        heading.current?.focus();
        (async () => {
            try {
                const response = await axios.get<{ notices: Notice[]; summary: { unread: number } }>('/notifications/preview', { signal: controller.signal, headers: { Accept: 'application/json' } });
                if (controller.signal.aborted) return;
                setNotices(response.data.notices); setCount(response.data.summary.unread);
                setLoading(false);
                const unread = response.data.notices.filter(notice => !notice.read_at).map(notice => notice.id);
                if (unread.length) {
                    try {
                        const saved = await axios.patch('/notifications/read-displayed', { ids: unread }, { signal: controller.signal, headers: { Accept: 'application/json' } });
                        if (!controller.signal.aborted) setCount(saved.data.summary.unread);
                    } catch {
                        if (!controller.signal.aborted) setError('Your updates loaded, but we could not mark them as read. Reopen notifications to try again.');
                    }
                }
            } catch {
                if (!controller.signal.aborted) setError('Notifications could not load. Please try again.');
            } finally { if (!controller.signal.aborted) setLoading(false); }
        })();
        return () => controller.abort();
    }, [open]);
    const close = () => { setOpen(false); trigger.current?.focus(); };
    return <div ref={container} className="shrink-0 sm:relative">
        <button ref={trigger} type="button" aria-label={notificationSummary?.available === false ? 'Notifications temporarily unavailable' : `Notifications${count ? `, ${count} unread` : ''}`} aria-expanded={open} aria-controls="buyer-notifications" onClick={() => setOpen(value => !value)} className="relative inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-[#E00D42]">
            <Bell className="h-[18px] w-[18px]" aria-hidden="true" />
            {count > 0 && <span aria-hidden="true" className="absolute -right-1 -top-1 flex min-h-4 min-w-4 items-center justify-center rounded-full bg-[#E00D42] px-1 text-[10px] font-semibold text-white">{count > 99 ? '99+' : count}</span>}
        </button>
        {open && <section id="buyer-notifications" aria-labelledby="buyer-notifications-title" className="absolute inset-x-4 top-full z-50 mt-3 max-h-[70dvh] overflow-y-auto rounded-2xl border border-slate-300 bg-white shadow-xl sm:inset-x-auto sm:right-0 sm:w-96">
            <div className="flex items-center justify-between gap-3 border-b border-slate-300 p-4">
                <div><h2 ref={heading} tabIndex={-1} id="buyer-notifications-title" className="text-base font-bold text-slate-900">Notifications</h2><p className="mt-1 text-xs text-slate-500">Recent updates{notificationSummary?.available !== false && ` · ${count} unread`}</p></div>
                <button type="button" onClick={close} aria-label="Close notifications" className="rounded-xl p-2 hover:bg-slate-100"><X className="h-4 w-4" /></button>
            </div>
            {loading && <p role="status" className="p-5 text-sm text-slate-600">Loading your updates...</p>}
            {error && <p role="alert" className="m-4 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">{error}</p>}
            {!loading && !error && notices.length === 0 && <p className="p-6 text-center text-sm text-slate-600">No notifications yet. Your order and account updates will appear here.</p>}
            <ul className="divide-y divide-slate-200">{notices.map(notice => <li key={notice.id}>
                <button type="button" disabled={opening !== null} onClick={() => { setOpening(notice.id); openNotice(notice.id, () => { setOpening(null); setOpen(false); }); }} className={`w-full p-4 text-left transition-colors hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[#E00D42] disabled:opacity-50 ${!notice.read_at ? 'bg-rose-50/70' : 'bg-white'}`}>
                    <span className="block text-sm font-bold text-slate-900">{!notice.read_at && <span aria-label="New update" className="mr-2 inline-block h-2 w-2 rounded-full bg-[#E00D42]" />}{notice.data.title ?? 'Account update'}</span>
                    <span className="mt-1 block text-xs leading-relaxed text-slate-600">{notice.data.body}</span>
                    <time dateTime={notice.created_at} className="mt-2 block text-[11px] text-slate-500">{noticeDate(notice.created_at)}</time>
                </button>
            </li>)}</ul>
            <Link href="/notifications" onClick={() => setOpen(false)} className="block border-t border-slate-300 p-4 text-center text-sm font-bold text-[#C20836] hover:bg-rose-50">View all notifications</Link>
        </section>}
    </div>;
}
