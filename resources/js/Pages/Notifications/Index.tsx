import { Head, Link, usePage } from '@inertiajs/react';
import { Bell, Check } from 'lucide-react';
import NotificationLayout, { type NotificationPortal } from '@/Layouts/NotificationLayout';
import { noticeDate, type Notice } from '@/Components/NotificationMenu';
import useNotificationAction from '@/hooks/useNotificationAction';
import { PageProps } from '@/types';

interface Props extends NotificationPortal {
    available: boolean;
    filter: 'all' | 'unread';
    notices: { data: Notice[]; current_page: number; last_page: number; prev_page_url: string | null; next_page_url: string | null } | null;
}

export default function Notifications({ available, filter, notices, ...portal }: Props) {
    const { notificationSummary } = usePage<PageProps>().props;
    const buyer = portal.portal === 'buyer';
    const Heading = portal.portal === 'dashboard' ? 'h2' : 'h1';
    const { reading, error, open, markRead } = useNotificationAction();
    const button = 'inline-flex min-h-10 items-center justify-center gap-2 rounded-[8px] border border-slate-300 px-3 py-2 text-sm font-semibold hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42]';
    return (
        <NotificationLayout {...portal}><div className="font-sans text-slate-900">
            <Head title="Notifications" />
            <section aria-label="All notifications" className={`mx-auto w-full max-w-4xl ${buyer ? 'py-6' : ''}`}>
                <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                    <div><Heading className="text-2xl font-bold">Notifications</Heading><p className="mt-1 text-sm text-slate-600">{notificationSummary?.available ? `${notificationSummary.unread} unread updates` : 'Updates for your Bagoo account'}</p></div>
                    <nav aria-label="Notification filters" className="flex gap-2">
                        {(['all', 'unread'] as const).map(value => <Link key={value} href={`/notifications?filter=${value}`} preserveScroll aria-current={filter === value ? 'page' : undefined} className={`${button} ${filter === value ? 'border-[#E00D42] bg-rose-50 text-[#E00D42]' : 'bg-white'}`}>{value === 'all' ? 'All' : 'Unread'}</Link>)}
                    </nav>
                </div>
                {error && <p role="alert" className="mb-4 rounded-[8px] border border-rose-300 bg-rose-50 p-4 text-sm text-rose-800">{error}</p>}
                {!available ? (
                    <div role="status" className="flex min-h-60 flex-col items-center justify-center rounded-[8px] border border-slate-300 bg-white p-6 text-center"><h2 className="font-semibold">Notifications are temporarily unavailable</h2><p className="mt-2 text-sm text-slate-600">Please try again shortly. Your saved order and account decisions are kept separately.</p><Link href={`/notifications?filter=${filter}`} className={`${button} mt-4`}>Try again</Link></div>
                ) : notices?.data.length === 0 ? (
                    <div className="flex min-h-60 flex-col items-center justify-center rounded-[8px] border border-slate-300 bg-white p-8 text-center shadow-sm"><Bell className="h-6 w-6 text-slate-500" aria-hidden="true" /><h2 className="mt-3 font-semibold">{filter === 'unread' ? 'You have no unread updates' : 'You have no notifications yet'}</h2><p className="mt-2 text-sm text-slate-600">{buyer ? 'Your order and account updates will appear here.' : 'Your account and work updates will appear here.'}</p></div>
                ) : (
                    <ul className="space-y-3">{notices?.data.map(notice => <li key={notice.id} className={`border p-4 shadow-sm sm:p-5 ${buyer ? 'rounded-2xl' : 'rounded-[8px]'} ${notice.read_at ? 'border-slate-300 bg-white' : 'border-rose-300 bg-rose-50/60'}`}>
                        <div className="flex flex-col items-start justify-between gap-2 sm:flex-row sm:gap-4"><h2 className="min-w-0 break-words font-semibold">{!notice.read_at && <span className="mr-2 inline-block h-2 w-2 rounded-full bg-[#E00D42]" aria-label="Unread" />}{notice.data.title ?? 'Account update'}</h2><time dateTime={notice.created_at} className="shrink-0 text-xs text-slate-500">{noticeDate(notice.created_at)}</time></div>
                        {notice.data.order_number && <p className="mt-1 text-xs text-slate-500">Order {notice.data.order_number}</p>}
                        {notice.data.body && <p className="mt-2 break-words text-sm text-slate-700">{notice.data.body}</p>}
                        {notice.data.hub_name && <div className="mt-3 space-y-1 rounded-[8px] border border-slate-300 bg-slate-50 p-3 text-sm"><p className="font-semibold">{notice.data.hub_name}</p><p>{notice.data.hub_address}</p><p>{notice.data.operating_hours}</p>{notice.data.expires_at && <p>Holding period ends {noticeDate(notice.data.expires_at)}</p>}</div>}
                        <div className="mt-3 flex flex-wrap items-center gap-2"><button type="button" disabled={reading !== null} onClick={() => open(notice.id)} className={`${button} text-[#E00D42] disabled:opacity-50`}>View update</button>{!notice.read_at ? <button type="button" disabled={reading !== null} onClick={() => markRead(notice.id)} className={`${button} disabled:opacity-50`}><Check className="h-4 w-4" aria-hidden="true" />{reading === notice.id ? 'Saving...' : 'Mark read'}</button> : <span className="text-xs text-slate-500">Read {noticeDate(notice.read_at)}</span>}</div>
                    </li>)}</ul>
                )}
                {available && notices && notices.last_page > 1 && <nav aria-label="Notification pages" className="mt-5 flex items-center justify-between gap-3">{notices.prev_page_url ? <Link href={notices.prev_page_url} className={`${button} bg-white`}>Previous</Link> : <span />}<span className="text-sm text-slate-600">Page {notices.current_page} of {notices.last_page}</span>{notices.next_page_url ? <Link href={notices.next_page_url} className={`${button} bg-white`}>Next</Link> : <span />}</nav>}
            </section>
        </div></NotificationLayout>
    );
}
