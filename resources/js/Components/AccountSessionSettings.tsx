import { useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { MonitorSmartphone, RefreshCw } from 'lucide-react';
import AccountSessionPanel from '@/Components/AccountSessionPanel';
import { confirmedSessionPage, sessionListUrl } from '@/utils/accountSessions';
import type { PageProps } from '@/types';
import type { AccountSessionProps } from '@/types/accountSessions';

export default function AccountSessionSettings({ enabled = true }: { enabled?: boolean }) {
    const user = usePage<PageProps>().props.auth.user;
    const userId = user?.id ?? 0;
    const [data, setData] = useState<AccountSessionProps | null>(null);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);
    const currentOwner = useRef(userId);
    currentOwner.current = userId;
    const request = useRef<AbortController | null>(null);
    const cursor = useRef('/account/security');
    const mounted = useRef(false);

    const load = async (href = cursor.current) => {
        if (!userId || !enabled) return;
        const url = sessionListUrl(href);
        request.current?.abort();
        const active = new AbortController(); request.current = active;
        const valid = () => mounted.current && !active.signal.aborted && currentOwner.current === userId;
        setLoading(true); setError('');
        try {
            const response = await axios.get(url, { headers: { Accept: 'application/json' }, signal: active.signal });
            if (!valid()) return;
            const saved = confirmedSessionPage(response.data, userId);
            cursor.current = url; setData(saved);
        } catch (problem) {
            if (!valid()) return;
            const status = axios.isAxiosError(problem) ? problem.response?.status : null;
            const message = status === 401 || status === 419 ? 'Your session has ended. Sign in again to review login activity.'
                : 'Login activity could not load. Check your connection and try again.';
            setError(message);
            throw new Error(message);
        } finally { if (valid()) setLoading(false); }
    };

    useEffect(() => {
        mounted.current = true;
        cursor.current = '/account/security'; setData(null); setError('');
        if (enabled && userId) void load('/account/security').catch(() => {});
        return () => { mounted.current = false; request.current?.abort(); };
    }, [userId, enabled]);

    const owned = enabled && data?.sessionOwnerId === userId && userId > 0;
    const card = user?.role === 'courier' ? 'courier-panel rounded-[8px] bg-white p-5 shadow-sm sm:p-6' : 'rounded-3xl border border-slate-300 bg-white p-5 shadow-xs sm:p-6';

    return <section id="login-activity" tabIndex={-1} className="scroll-mt-28 focus:outline-none" aria-label="Login activity">
        {owned ? <AccountSessionPanel key={userId} {...data} onRefresh={() => load()} onPageChange={load} />
            : <div className={card} aria-busy={loading}>
                <div className="flex items-center gap-3"><MonitorSmartphone className="h-5 w-5 text-[#C20836]" aria-hidden="true" /><h2 className="text-base font-bold text-slate-900">Login activity</h2></div>
                <div className="flex min-h-40 flex-col items-center justify-center gap-3 text-center">
                    {error ? <><p role="alert" className="max-w-md text-sm leading-relaxed text-rose-700">{error}</p><button type="button" onClick={() => void load('/account/security').catch(() => {})} disabled={loading} className="inline-flex min-h-11 items-center gap-2 rounded-[8px] border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42]"><RefreshCw className="h-4 w-4" aria-hidden="true" />Try again</button></>
                        : <p role="status" className="text-sm text-slate-500">{enabled && userId ? 'Loading your signed-in sessions…' : 'Sign in to review your login activity.'}</p>}
                </div>
            </div>}
    </section>;
}
