import { Link, usePage } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { PageProps } from '@/types';

export default function NotificationLink() {
    const { auth, notificationSummary } = usePage<PageProps>().props;
    if (!auth.user) return null;
    const count = notificationSummary?.unread ?? 0;
    const label = notificationSummary?.available === false
        ? 'Notifications temporarily unavailable'
        : `Notifications${count ? `, ${count} unread` : ''}`;

    return (
        <Link href="/notifications" aria-label={label} title={label}
            className="relative inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-[8px] border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42]">
            <Bell className="h-[18px] w-[18px]" aria-hidden="true" />
            {count > 0 && <span aria-hidden="true" className="absolute -right-1 -top-1 flex min-h-4 min-w-4 items-center justify-center rounded-full bg-[#E00D42] px-1 text-[10px] font-semibold text-white">{count > 99 ? '99+' : count}</span>}
        </Link>
    );
}
