import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { DialogTitle } from '@headlessui/react';
import axios from 'axios';
import { ArrowLeft, Check, Clock3, Globe, History, Laptop, LogOut, MonitorSmartphone, RefreshCw, ShieldCheck, Smartphone, Tablet } from 'lucide-react';
import BuyerOrderAccessLayout from '@/Layouts/BuyerOrderAccessLayout';
import DashboardLayout from '@/Layouts/DashboardLayout';
import CourierLayout from '@/Layouts/CourierLayout';
import GuestLayout from '@/Layouts/GuestLayout';
import Modal from '@/Components/Modal';
import VoucherPagination from '@/Components/Vouchers/VoucherPagination';
import { refreshPage, requestErrors } from '@/utils/accountRequests';
import { confirmedSessionResult, sessionDate, sessionRequestToken } from '@/utils/accountSessions';
import type { AccountSession, AccountSessionProps } from '@/types/accountSessions';
import type { PageProps } from '@/types';

type Selection = { kind: 'one'; session: AccountSession } | { kind: 'others' };

export default function Security({ sessions, signIns, lastSignIn, sessionOwnerId, canConfirmByEmail, canUsePortal, isOnline, historyDays, scope }: AccountSessionProps) {
    const { auth } = usePage<PageProps>().props;
    const user = auth.user;
    const userId = user?.id ?? 0;
    const rider = user?.role === 'courier';
    const [selection, setSelection] = useState<Selection | null>(null);
    const [method, setMethod] = useState<'password' | 'email'>('password');
    const [password, setPassword] = useState('');
    const [code, setCode] = useState('');
    const [busy, setBusy] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [result, setResult] = useState('');
    const [sent, setSent] = useState(false);
    const [cooldown, setCooldown] = useState(0);
    const pending = useRef(false);
    const intent = useRef('');
    const controller = useRef<AbortController | null>(null);
    const owner = useRef(userId);
    owner.current = userId;
    const previousOwner = useRef(userId);
    const radius = rider ? 'rounded-[8px]' : 'rounded-xl';
    const card = rider ? 'courier-panel rounded-[8px] bg-white shadow-sm' : 'rounded-xl border border-slate-300 bg-white';
    const button = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-[8px] border border-slate-300 px-4 py-2 text-sm font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42] disabled:cursor-not-allowed disabled:opacity-50';

    useEffect(() => () => controller.current?.abort(), []);
    useEffect(() => {
        if (previousOwner.current === userId) return;
        controller.current?.abort(); pending.current = false; intent.current = '';
        setSelection(null); setPassword(''); setCode(''); setResult(''); setErrors({}); setSent(false); setCooldown(0); setBusy('');
        previousOwner.current = userId;
    }, [userId]);
    useEffect(() => {
        if (cooldown <= 0) return;
        const timer = window.setTimeout(() => setCooldown(value => Math.max(0, value - 1)), 1000);
        return () => window.clearTimeout(timer);
    }, [cooldown]);

    const close = () => {
        if (pending.current) return;
        setSelection(null); setPassword(''); setCode(''); setErrors({}); setSent(false); intent.current = '';
    };
    const choose = (next: Selection) => {
        if (pending.current) return;
        try { intent.current = sessionRequestToken(); }
        catch (error) { setErrors(requestErrors(error)); return; }
        setSelection(next); setMethod('password'); setPassword(''); setCode(''); setErrors({}); setResult(''); setSent(false);
    };
    const begin = (kind: string) => {
        if (pending.current || owner.current === 0 || owner.current !== sessionOwnerId) return null;
        pending.current = true; setBusy(kind); setErrors({});
        const active = new AbortController(); controller.current = active;
        return { active, account: owner.current };
    };
    const current = (request: NonNullable<ReturnType<typeof begin>>) => !request.active.signal.aborted && request.account === owner.current;
    const finish = (request: NonNullable<ReturnType<typeof begin>>) => {
        if (current(request)) { pending.current = false; setBusy(''); }
    };
    const sendCode = async () => {
        if (cooldown > 0) return;
        const request = begin('code'); if (!request) return;
        try {
            const response = await axios.post('/account/security/code', {}, { headers: { Accept: 'application/json' }, signal: request.active.signal });
            if (!current(request)) return;
            if (response.data?.success !== true) throw new Error('We could not confirm that a code was sent. Try again shortly.');
            setSent(true); setCooldown(Math.ceil(response.data.cooldown ?? 60));
        } catch (error) {
            if (current(request)) {
                setErrors(requestErrors(error));
                if (axios.isAxiosError(error) && error.response?.data?.cooldown) setCooldown(Math.ceil(error.response.data.cooldown));
            }
        } finally { finish(request); }
    };
    const submit = async (event: FormEvent) => {
        event.preventDefault();
        if (!selection || pending.current) return;
        if (method === 'password' && password.length === 0) { setErrors({ current_password: 'Enter your current password.' }); return; }
        if (method === 'email' && !/^[0-9]{6}$/.test(code)) { setErrors({ code: 'Enter the six-digit code from your email.' }); return; }
        const request = begin('revoke'); if (!request) return;
        const path = selection.kind === 'others' ? '/account/security/sessions/revoke-others' : `/account/security/sessions/${encodeURIComponent(selection.session.id)}/revoke`;
        try {
            const response = await axios.post(path, { request_token: intent.current, confirmation_method: method,
                current_password: method === 'password' ? password : null, code: method === 'email' ? code : null },
            { headers: { Accept: 'application/json' }, signal: request.active.signal });
            if (!current(request)) return;
            const saved = confirmedSessionResult(response.data);
            setPassword(''); setCode(''); setSelection(null); setSent(false); intent.current = '';
            setResult(saved.message);
            await refreshPage();
        } catch (error) {
            if (current(request)) setErrors(axios.isAxiosError(error) && (!error.response || error.response.status >= 500)
                ? { request: 'Sign-out could not be confirmed. Retry to check the result of this request, or refresh login activity.' }
                : requestErrors(error));
        } finally { finish(request); }
    };
    const refresh = async () => {
        const request = begin('refresh'); if (!request) return;
        try { await refreshPage(); }
        catch (error) { if (current(request)) setErrors(requestErrors(error)); }
        finally { finish(request); }
    };
    const wrap = (children: ReactNode) => user?.role === 'buyer'
        ? <BuyerOrderAccessLayout canUsePortal={canUsePortal}>{children}</BuyerOrderAccessLayout>
        : !canUsePortal ? <GuestLayout title="Login activity" subtitle="Manage access to your account." maxWidth="5xl" noCard guestOnly={false}>{children}</GuestLayout>
        : rider ? <CourierLayout title="Login activity" isOnline={isOnline} scope={scope} hidePageHeading>{children}</CourierLayout>
        : <DashboardLayout title="Login activity" subtitle="Manage your account’s browser and app sessions.">{children}</DashboardLayout>;
    const status = (session: AccountSession) => session.current ? 'This session' : session.active ? 'Signed in' : session.status === 'expired' ? 'Expired' : 'Signed out';
    const icon = (session: AccountSession) => session.device_type === 'phone' ? Smartphone : session.device_type === 'tablet' ? Tablet : session.device_type === 'computer' ? Laptop : MonitorSmartphone;
    const errorsView = <div aria-live="polite" className="space-y-1">{Object.entries(errors).map(([key, message]) => <p key={key} role="alert" className="text-sm leading-relaxed text-rose-700">{message}</p>)}</div>;

    if (!user) return <GuestLayout title="Sign in required" guestOnly={false}><p className="text-sm text-slate-600">Sign in to review your login activity.</p><Link href="/login" className="mt-4 inline-block font-semibold text-[#C20836]">Sign in</Link></GuestLayout>;
    if (sessionOwnerId !== user.id) return wrap(<div className="mx-auto max-w-5xl p-6"><p className="text-sm text-slate-600">Reload login activity to review the current account.</p><Link href="/account/security" className="mt-4 inline-block font-semibold text-[#C20836]">Reload login activity</Link></div>);

    return wrap(<>
        <Head title="Login activity — BagooPH" />
        <div className="mx-auto max-w-5xl space-y-6 px-4 py-6 sm:px-6" data-account-security="true" aria-busy={Boolean(busy)}>
            <Link href={canUsePortal ? '/account/settings' : '/account/status'} className="inline-flex min-h-11 items-center gap-2 text-sm font-semibold text-slate-600 hover:text-[#C20836]"><ArrowLeft className="h-4 w-4" aria-hidden="true" />{canUsePortal ? 'Back to settings' : 'Back to account status'}</Link>
            <header className="flex flex-wrap items-start justify-between gap-4 border-b border-slate-300 pb-5"><div><h1 className="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">Login activity</h1><p className="mt-2 max-w-2xl text-sm leading-relaxed text-slate-600">Review where your account is signed in. Each entry is a browser or app session; several can belong to the same device.</p></div><button type="button" onClick={() => void refresh()} disabled={Boolean(busy)} className={`${button} bg-white text-slate-700`}><RefreshCw className="h-4 w-4" aria-hidden="true" />{busy === 'refresh' ? 'Refreshing…' : 'Refresh'}</button></header>
            {result && <p role="status" className={`${radius} flex items-start gap-2 border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800`}><Check className="h-4 w-4 shrink-0" aria-hidden="true" />{result}</p>}
            {!selection && errorsView}
            <section className={`${card} p-5 sm:p-6`} aria-labelledby="last-sign-in-title"><div className="flex items-start gap-3"><ShieldCheck className="mt-0.5 h-5 w-5 shrink-0 text-[#E00D42]" aria-hidden="true" /><div><h2 id="last-sign-in-title" className="text-base font-semibold text-slate-900">Latest successful sign-in</h2>{lastSignIn ? <><p className="mt-2 text-sm font-semibold text-slate-900">{sessionDate(lastSignIn.signed_in_at)}</p><p className="mt-1 text-sm text-slate-600">{lastSignIn.device}{lastSignIn.network ? ` · Network ${lastSignIn.network}` : ''}</p></> : <p className="mt-2 text-sm leading-relaxed text-slate-600">A sign-in time was not recorded for your earlier sessions. Your next successful sign-in will appear here.</p>}<p className="mt-2 text-xs leading-relaxed text-slate-500">Times use Philippine time. Opening a page updates activity, not the sign-in time.</p></div></div></section>

            <section className={`${card} overflow-hidden`} aria-labelledby="sessions-title"><div className="flex flex-wrap items-center justify-between gap-4 border-b border-slate-300 p-5 sm:p-6"><div><h2 id="sessions-title" className="text-lg font-semibold text-slate-900">Signed-in sessions</h2><p className="mt-1 text-sm text-slate-600">{sessions.total} signed-in {sessions.total === 1 ? 'session' : 'sessions'}. Account restrictions still apply.</p></div><button type="button" onClick={() => choose({ kind: 'others' })} disabled={Boolean(busy)} className={`${button} bg-white text-[#C20836]`}><LogOut className="h-4 w-4" aria-hidden="true" />Sign out others</button></div>
                {sessions.data.length ? <ul className="divide-y divide-slate-200">{sessions.data.map(session => { const Icon = icon(session); return <li key={session.id} className="flex flex-wrap items-start gap-4 p-5 sm:p-6"><div className={`${rider ? 'rounded-[8px]' : 'rounded-lg'} flex h-11 w-11 shrink-0 items-center justify-center bg-slate-50 text-slate-600`}><Icon className="h-5 w-5" aria-hidden="true" /></div><div className="min-w-0 flex-1 space-y-2"><div className="flex flex-wrap items-center gap-2"><h3 className="break-words text-sm font-semibold text-slate-900">{session.device}</h3><span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">{status(session)}</span></div><p className="text-xs text-slate-500">{session.channel === 'rider' ? 'Rider app' : 'Website'} · Signed in: {sessionDate(session.signed_in_at)}</p><p className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs leading-relaxed text-slate-600"><span className="inline-flex items-center gap-1.5"><Clock3 className="h-3.5 w-3.5" aria-hidden="true" />Activity: {sessionDate(session.last_seen_at)}</span><span className="inline-flex items-center gap-1.5"><Globe className="h-3.5 w-3.5" aria-hidden="true" />Network: {session.network ?? 'Not recorded'}</span></p>{!session.signed_in_at && <p className="text-xs leading-relaxed text-slate-500">This session started before sign-in history was recorded.</p>}</div><button type="button" disabled={Boolean(busy)} onClick={() => session.current ? router.post(route('logout')) : choose({ kind: 'one', session })} className={`${button} bg-white text-slate-700`} aria-label={session.current ? 'Sign out this session' : `Sign out ${session.device}`}>Sign out</button></li>; })}</ul>
                    : <div className="flex min-h-48 flex-col items-center justify-center gap-2 p-6 text-center"><MonitorSmartphone className="h-8 w-8 text-slate-400" aria-hidden="true" /><p className="text-sm font-semibold text-slate-900">No sessions on this page</p><Link href="/account/security" className="text-sm font-semibold text-[#C20836]">Return to current sessions</Link></div>}
                <VoucherPagination page={sessions} label="Signed-in session pages" />
            </section>
            <p className="text-xs leading-relaxed text-slate-500">Device descriptions are approximate and network addresses are partly hidden. A VPN or shared connection can change them. Exact location is not tracked. Signing out a session does not change assigned work or rider duty.</p>

            <section className={`${card} overflow-hidden`} aria-labelledby="history-title"><div className="border-b border-slate-300 p-5 sm:p-6"><h2 id="history-title" className="flex items-center gap-2 text-lg font-semibold text-slate-900"><History className="h-5 w-5 text-slate-500" aria-hidden="true" />Recent sign-ins</h2><p className="mt-1 text-sm text-slate-600">Successful sign-ins from the last {historyDays} days. Earlier unrecorded sessions are not treated as new logins.</p></div>{signIns.data.length ? <ul className="divide-y divide-slate-200">{signIns.data.map(session => <li key={session.id} className="flex flex-wrap items-start justify-between gap-3 p-5 sm:px-6"><div className="min-w-0"><p className="break-words text-sm font-semibold text-slate-900">{session.device}</p><p className="mt-1 text-xs leading-relaxed text-slate-500">{sessionDate(session.signed_in_at)} · {session.channel === 'rider' ? 'Rider app' : 'Website'}{session.method === 'remembered' ? ' · Saved sign-in' : ''}</p><p className="mt-1 text-xs text-slate-500">Network: {session.network ?? 'Not recorded'}</p></div><span className="text-xs font-semibold text-slate-600">{status(session)}</span></li>)}</ul> : <div className="flex min-h-44 flex-col items-center justify-center gap-2 p-6 text-center"><History className="h-8 w-8 text-slate-400" aria-hidden="true" /><p className="text-sm font-semibold text-slate-900">No recorded sign-ins in this period</p><p className="max-w-md text-sm leading-relaxed text-slate-500">Successful sign-ins will appear here when you next sign in.</p></div>}<VoucherPagination page={signIns} label="Sign-in history pages" /></section>
        </div>

        <Modal show={selection !== null} onClose={close} closeable={!busy} maxWidth="lg"><form onSubmit={event => void submit(event)} className="space-y-5 p-5 sm:p-6" aria-busy={Boolean(busy)}><div><DialogTitle className="text-xl font-bold text-slate-900">{selection?.kind === 'others' ? 'Sign out other sessions?' : 'Sign out this session?'}</DialogTitle><p className="mt-2 break-words text-sm leading-relaxed text-slate-600">{selection?.kind === 'one' ? `${selection.session.device} will need to sign in again. Your current session stays open.` : 'End all other browser and rider-app sessions. Your current session stays open.'}</p><p className="mt-2 text-xs leading-relaxed text-slate-500">Saved sign-ins are reset across devices; other active sessions stay open unless selected.</p></div>
            {canConfirmByEmail && <fieldset className="flex flex-wrap gap-4 text-sm"><legend className="mb-2 font-semibold text-slate-700">Confirm it is you</legend>{(['password', 'email'] as const).map(value => <label key={value} className="inline-flex min-h-10 items-center gap-2"><input type="radio" name="confirmation-method" value={value} checked={method === value} disabled={Boolean(busy)} onChange={() => { setMethod(value); setPassword(''); setCode(''); setErrors({}); }} className="border-slate-300 text-[#E00D42] focus:ring-[#E00D42]" />{value === 'password' ? 'Current password' : 'Email code'}</label>)}</fieldset>}
            {method === 'password' ? <label className="block text-sm font-semibold text-slate-700">Current password<input type="password" autoComplete="current-password" value={password} maxLength={4096} disabled={Boolean(busy)} onChange={event => setPassword(event.target.value)} className="mt-2 block min-h-11 w-full rounded-[8px] border-slate-300 focus:border-[#E00D42] focus:ring-[#E00D42]" aria-invalid={Boolean(errors.current_password)} /></label>
                : <div className="space-y-4"><div><p className="text-sm leading-relaxed text-slate-600">Send a one-time code to your verified sign-in email. The code expires after ten minutes.</p><button type="button" onClick={() => void sendCode()} disabled={Boolean(busy) || cooldown > 0} className={`${button} mt-3 bg-white text-slate-700`}>{busy === 'code' ? 'Sending…' : cooldown > 0 ? `Resend in ${cooldown}s` : sent ? 'Resend code' : 'Send code'}</button>{sent && <p role="status" className="mt-2 text-xs text-emerald-700">Code sent. Check your sign-in email.</p>}</div><label className="block text-sm font-semibold text-slate-700">Email code<input type="text" autoComplete="one-time-code" inputMode="numeric" maxLength={6} value={code} disabled={Boolean(busy)} onChange={event => setCode(event.target.value)} className="mt-2 block min-h-11 w-full rounded-[8px] border-slate-300 focus:border-[#E00D42] focus:ring-[#E00D42]" aria-invalid={Boolean(errors.code) || (code.length > 0 && !/^[0-9]{6}$/.test(code))} />{code.length > 0 && !/^[0-9]{6}$/.test(code) && <span className="mt-1 block text-xs text-rose-700">Enter the six numbers from your email.</span>}</label></div>}
            {errorsView}<div className="flex flex-wrap justify-end gap-3 border-t border-slate-200 pt-4"><button type="button" onClick={close} disabled={Boolean(busy)} className={`${button} bg-white text-slate-700`}>Cancel</button><button type="submit" disabled={Boolean(busy)} className={`${button} border-[#E00D42] bg-[#E00D42] text-white hover:bg-[#C20836]`}>{busy === 'revoke' ? 'Signing out…' : 'Confirm sign-out'}</button></div>
        </form></Modal>
    </>);
}
