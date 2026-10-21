import { Link } from '@inertiajs/react';
import { ChevronRight, MonitorSmartphone } from 'lucide-react';

export default function AccountSessionLink({ theme = 'default' }: { theme?: 'default' | 'buyer' | 'seller' | 'courier' }) {
    const card = theme === 'courier' ? 'courier-panel rounded-[8px] bg-white p-4 shadow-sm sm:p-6' : 'rounded-xl border border-slate-300 bg-white p-5';
    return <section className={`${card} flex flex-wrap items-center justify-between gap-4`} aria-label="Login activity and devices" data-session-security-link={theme}>
        <div className="flex min-w-0 items-start gap-3"><MonitorSmartphone className="mt-0.5 h-5 w-5 shrink-0 text-[#E00D42]" aria-hidden="true" /><div><h2 className="text-base font-semibold text-slate-900">Login activity</h2><p className="mt-1 text-sm leading-relaxed text-slate-600">Review your sign-ins and sign out sessions you no longer use.</p></div></div>
        <Link href="/account/security" className="inline-flex min-h-11 items-center gap-2 rounded-[8px] border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-[#C20836] hover:bg-rose-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42]">View devices<ChevronRight className="h-4 w-4" aria-hidden="true" /></Link>
    </section>;
}
