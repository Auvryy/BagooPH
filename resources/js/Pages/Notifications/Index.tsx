import { Head, Link, usePage } from '@inertiajs/react';
import { Bell, Check } from 'lucide-react';
import NotificationLayout, { type NotificationPortal } from '@/Layouts/NotificationLayout';
import { noticeDate, type Notice } from '@/Components/NotificationMenu';
import useNotificationAction from '@/hooks/useNotificationAction';
import { HubPageHeader } from '@/Components/Logistics/Workspace';
import { PageProps } from '@/types';

interface Props extends NotificationPortal {
    available: boolean;
    filter: 'all' | 'unread';
    notices: { data: Notice[]; current_page: number; last_page: number; prev_page_url: string | null; next_page_url: string | null } | null;
    readSnapshot?: { id: string; count: number; expires_at: string } | null;
}

export default function Notifications({ available, filter, notices, readSnapshot, ...portal }: Props) {
    const { notificationSummary, auth } = usePage<PageProps>().props;
    const buyer = portal.portal === 'buyer';
    const logistics = auth.user?.role === 'logistics';
    const Heading = portal.portal === 'dashboard' ? 'h2' : 'h1';
    const { reading, error, success, open, markRead, markAll } = useNotificationAction();
    const button = 'inline-flex min-h-10 items-center justify-center gap-2 rounded-[8px] border border-slate-300 px-3 py-2 text-sm font-semibold hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42]';
    return (
        <NotificationLayout {...portal}><div className="font-sans text-slate-900">
            <Head title="Notifications" />
            <section aria-label="All notifications" className={`w-full ${logistics ? '' : 'mx-auto max-w-4xl'} ${buyer ? 'py-6' : ''}`}>
                <div className={`mb-5 ${buyer ? 'rounded-3xl border border-rose-100 bg-gradient-to-br from-white via-white to-rose-50/80 p-5 shadow-sm sm:p-6' : 'flex flex-wrap items-center justify-between gap-3'}`}>
                    <div className={`flex flex-wrap items-center justify-between gap-4 ${buyer ? '' : 'w-full'}`}>
                        {logistics ? <HubPageHeader title="Notifications" icon={Bell} description={notificationSummary?.available ? `${notificationSummary.unread} unread updates for your account and work.` : 'Updates for your account and work.'} /> : <div className="flex min-w-0 items-center gap-3">
                            {buyer && <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-rose-100 text-[#E00D42]"><Bell className="h-6 w-6" aria-hidden="true" /></span>}
                            <div><Heading className="text-2xl font-bold">Notifications</Heading><p className="mt-1 text-sm text-slate-600">{buyer ? 'Order, message, and account updates in one place.' : notificationSummary?.available ? `${notificationSummary.unread} unread updates` : 'Updates for your Bagoo account'}</p></div>
                        </div>}
                        {buyer && <span className="inline-flex min-h-9 items-center gap-2 rounded-full border border-rose-200 bg-white px-3 text-xs font-semibold text-slate-700"><span className="h-2 w-2 rounded-full bg-[#E00D42]" aria-hidden="true" />{notificationSummary?.available ? `${notificationSummary.unread} unread` : 'Updates'}</span>}
                    </div>
                    <nav aria-label="Notification filters" className={`flex gap-2 ${buyer ? 'mt-5 border-t border-rose-100 pt-4' : ''}`}>
                        {(['all', 'unread'] as const).map(value => <Link key={value} href={`/notifications?filter=${value}`} preserveScroll aria-label={value === 'unread' ? 'Unread' : 'All updates'} aria-current={filter === value ? 'page' : undefined} className={`${button} ${buyer ? 'rounded-full px-4' : ''} ${filter === value ? 'border-[#E00D42] bg-rose-50 text-[#E00D42]' : 'bg-white'}`}>{value === 'all' ? 'All updates' : 'Unread'}</Link>)}
                    </nav>
                </div>
                {available && readSnapshot && <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <p className="max-w-md text-xs text-slate-600">Includes unread updates on other pages. Updates received after this list opened will stay unread.</p>
                    <button type="button" disabled={reading !== null} aria-busy={reading === 'all' || undefined} onClick={() => markAll(readSnapshot.id, filter)} className={`${button} bg-white text-[#E00D42] disabled:opacity-50`}><Check className="h-4 w-4" aria-hidden="true" />{reading === 'all' ? 'Saving…' : 'Mark all as read'}</button>
                </div>}
                {error && <div role="alert" className={`mb-4 border border-rose-300 bg-rose-50 p-4 text-sm text-rose-800 ${buyer ? 'rounded-2xl' : 'rounded-[8px]'}`}><p>{error}</p><Link href={`/notifications?filter=${filter}`} preserveScroll className="mt-2 inline-block font-semibold underline">Reload notifications</Link></div>}
                {success && !error && <p role="status" className={`mb-4 border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-800 ${buyer ? 'rounded-2xl' : 'rounded-[8px]'}`}>{success}</p>}
                {!available ? (
                    <div role="status" className={`flex min-h-60 flex-col items-center justify-center border border-slate-300 bg-white p-6 text-center ${buyer ? 'rounded-3xl shadow-sm' : 'rounded-[8px]'}`}><span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-500"><Bell className="h-6 w-6" aria-hidden="true" /></span><h2 className="mt-3 font-semibold">Notifications are temporarily unavailable</h2><p className="mt-2 text-sm text-slate-600">Please try again shortly. Your saved order and account decisions are kept separately.</p><Link href={`/notifications?filter=${filter}`} className={`${button} mt-4`}>Try again</Link></div>
                ) : notices?.data.length === 0 ? (
                    <div className={`flex min-h-60 flex-col items-center justify-center border border-slate-200 bg-white p-8 text-center shadow-sm ${buyer ? 'rounded-3xl' : 'rounded-[8px]'}`}><span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-rose-50 text-[#E00D42]"><Bell className="h-6 w-6" aria-hidden="true" /></span><h2 className="mt-3 font-semibold">{filter === 'unread' ? 'You have no unread updates' : 'You have no notifications yet'}</h2><p className="mt-2 max-w-md text-sm text-slate-600">{buyer ? 'Your order and account updates will appear here. You can return to shopping while you wait.' : 'Your account and work updates will appear here.'}</p>{buyer && <Link href={route('buyer.search')} className="mt-4 inline-flex min-h-10 items-center rounded-full bg-[#E00D42] px-4 text-sm font-semibold text-white hover:bg-[#C20836]">Browse products</Link>}</div>
                ) : (
                    <ul className="space-y-3">{notices?.data.map(notice => <li key={notice.id} className={`relative overflow-hidden border p-4 transition sm:p-5 ${buyer ? 'rounded-2xl shadow-sm hover:shadow-md' : 'rounded-[8px] shadow-sm'} ${notice.read_at ? 'border-slate-200 bg-white' : 'border-rose-200 bg-gradient-to-r from-rose-50/90 to-white'}`}>
                        {!notice.read_at && <span className="absolute inset-y-0 left-0 w-1 bg-[#E00D42]" aria-hidden="true" />}
                        <div className="flex gap-3 sm:gap-4">
                            {buyer && <span className={`mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${notice.read_at ? 'bg-slate-100 text-slate-500' : 'bg-rose-100 text-[#E00D42]'}`}><Bell className="h-5 w-5" aria-hidden="true" /></span>}
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-col items-start justify-between gap-2 sm:flex-row sm:gap-4"><h2 className="min-w-0 break-words font-semibold">{notice.data.title ?? 'Account update'}</h2><time dateTime={notice.created_at} className="shrink-0 text-xs text-slate-500">{noticeDate(notice.created_at)}</time></div>
                                {notice.data.order_number && <p className="mt-1 text-xs font-medium text-slate-500">Order {notice.data.order_number}</p>}
                                {notice.data.body && <p className="mt-2 break-words text-sm leading-6 text-slate-700">{notice.data.body}</p>}
                                {notice.data.hub_name && <div className="mt-3 space-y-1 rounded-xl border border-slate-200 bg-white/80 p-3 text-sm"><p className="font-semibold">{notice.data.hub_name}</p><p>{notice.data.hub_address}</p><p>{notice.data.operating_hours}</p>{notice.data.expires_at && <p>Holding period ends {noticeDate(notice.data.expires_at)}</p>}</div>}
                                <div className="mt-3 flex flex-wrap items-center gap-2"><button type="button" disabled={reading !== null} onClick={() => open(notice.id)} className={`${button} ${buyer ? 'rounded-full bg-white text-[#E00D42]' : 'text-[#E00D42]'} disabled:opacity-50`}>View update</button>{!notice.read_at ? <button type="button" disabled={reading !== null} onClick={() => markRead(notice.id)} className={`${button} ${buyer ? 'rounded-full bg-white' : ''} disabled:opacity-50`}><Check className="h-4 w-4" aria-hidden="true" />{reading === notice.id ? 'Saving...' : 'Mark read'}</button> : <span className="text-xs text-slate-500">Read {noticeDate(notice.read_at)}</span>}</div>
                            </div>
                        </div>
                    </li>)}</ul>
                )}
                {available && notices && notices.last_page > 1 && <nav aria-label="Notification pages" className="mt-5 flex items-center justify-between gap-3">{notices.prev_page_url ? <Link href={notices.prev_page_url} className={`${button} bg-white`}>Previous</Link> : <span />}<span className="text-sm text-slate-600">Page {notices.current_page} of {notices.last_page}</span>{notices.next_page_url ? <Link href={notices.next_page_url} className={`${button} bg-white`}>Next</Link> : <span />}</nav>}
            </section>
        </div></NotificationLayout>
    );
}
