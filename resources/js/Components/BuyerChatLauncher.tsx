import React from 'react';
import { Link } from '@inertiajs/react';
import { MessageCircle } from 'lucide-react';

export default function BuyerChatLauncher({ href, unreadCount = 0 }: { href: string; unreadCount?: number }) {
    return (
        <Link
            href={href}
            aria-label={unreadCount > 0 ? `Open messages, ${unreadCount} unread` : 'Open messages'}
            title="Messages"
            className="fixed bottom-[calc(1.25rem+env(safe-area-inset-bottom))] right-4 z-40 flex h-14 w-14 items-center justify-center rounded-full bg-[#E00D42] text-white shadow-[0_6px_24px_rgba(224,13,66,0.35)] transition-transform duration-200 hover:scale-105 hover:bg-[#C20836] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rose-200 focus-visible:ring-offset-2 sm:right-6"
        >
            <MessageCircle className="h-6 w-6" aria-hidden="true" />
            {unreadCount > 0 && (
                <span aria-hidden="true" className="absolute -right-1 -top-1 flex min-h-6 min-w-6 items-center justify-center rounded-full border-2 border-white bg-[#F97316] px-1 text-[10px] font-bold text-white shadow-sm">
                    {unreadCount > 99 ? '99+' : unreadCount}
                </span>
            )}
        </Link>
    );
}
