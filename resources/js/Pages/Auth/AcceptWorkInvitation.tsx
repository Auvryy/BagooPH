import { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { KeyRound, Mail, ShieldCheck } from 'lucide-react';
import GuestLayout from '@/Layouts/GuestLayout';

export default function AcceptWorkInvitation() {
    const [email, setEmail] = useState('');
    const [kind, setKind] = useState('operator');
    const [code, setCode] = useState('');
    const [token, setToken] = useState('');
    const [assignmentToken, setAssignmentToken] = useState('');
    const [assignment, setAssignment] = useState<{ company: string; hub: string } | null>(null);
    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [accepted, setAccepted] = useState(false);
    const [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState('');
    const [error, setError] = useState('');
    const [sent, setSent] = useState(false);
    const [done, setDone] = useState(false);
    const input = 'mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm text-slate-900 focus:border-[#E00D42] focus:ring-[#E00D42]';
    const button = 'rounded-lg bg-[#E00D42] px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50';
    const request = async (action: 'send' | 'verify' | 'activate') => {
        if (busy) return;
        setBusy(true); setError(''); setNotice('');
        try {
            const { data } = await axios.post(`/work-account/${action}`, { email, kind,
                ...(action === 'verify' ? { code } : {}),
                ...(action === 'activate' ? { token, assignment_token: assignmentToken, password, password_confirmation: confirmation, assignment_confirmed: accepted } : {}),
            });
            setNotice(data.message);
            if (action === 'send') { setSent(true); setToken(''); setAssignment(null); setCode(''); }
            if (action === 'verify') { setToken(data.token); setAssignmentToken(data.assignment_token); setAssignment(data.assignment); setAccepted(false); }
            if (action === 'activate') { setDone(true); setToken(''); setCode(''); }
        } catch (failure: unknown) {
            const response = axios.isAxiosError(failure) ? failure.response?.data : null;
            const errors = Object.values(response?.errors ?? {}).flat();
            setError(typeof errors[0] === 'string' ? errors[0] : response?.message ?? 'Could not complete setup. Please try again.');
        } finally {
            if (action === 'activate') { setPassword(''); setConfirmation(''); }
            setBusy(false);
        }
    };
    return <GuestLayout guestOnly={false}><Head title="Set up your work account" />
        <main className="mx-auto w-full max-w-md space-y-5 p-6">
            <span className="inline-flex rounded-lg bg-rose-50 p-3 text-[#E00D42]"><KeyRound aria-hidden="true" /></span>
            <div><h1 className="text-2xl font-bold text-slate-950">Set up your work account</h1><p className="mt-2 text-sm leading-6 text-slate-600">Use the email your company invited. Verify your code, review your assignment, then choose your own password.</p></div>
            {error && <p role="alert" className="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">{error}</p>}
            {notice && <p role="status" className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">{notice}</p>}
            {done ? <div className="space-y-3"><ShieldCheck className="text-[#E00D42]" /><Link href={kind === 'rider' ? '/courier/login' : '/hub/login'} className={`${button} inline-block`}>Sign in to your work account</Link></div> : <>
                <form className="space-y-4" onSubmit={event => { event.preventDefault(); void request('send'); }}>
                    <label className="block text-sm font-semibold text-slate-800">Work email<input required disabled={busy || Boolean(token)} type="email" maxLength={255} autoComplete="email" value={email} onChange={event => { setEmail(event.target.value); setSent(false); setCode(''); }} className={input} /></label>
                    <label className="block text-sm font-semibold text-slate-800">Account type<select disabled={busy || Boolean(token)} value={kind} onChange={event => { setKind(event.target.value); setSent(false); setCode(''); }} className={input}><option value="operator">Hub operator</option><option value="rider">Rider</option></select></label>
                    {!token && <button disabled={busy} className={`${button} inline-flex items-center gap-2`}><Mail size={16} />{busy ? 'Sending…' : sent ? 'Resend setup code' : 'Email my setup code'}</button>}
                </form>
                {sent && !token && <form className="space-y-4 border-t border-slate-200 pt-4" onSubmit={event => { event.preventDefault(); void request('verify'); }}>
                    <label className="block text-sm font-semibold text-slate-800">Six-digit code<input required inputMode="numeric" autoComplete="one-time-code" pattern="[0-9]{6}" minLength={6} maxLength={6} value={code} onChange={event => setCode(event.target.value)} className={input} /></label><button disabled={busy} className={button}>{busy ? 'Checking…' : 'Verify code'}</button>
                </form>}
                {token && assignment && <form className="space-y-4" onSubmit={event => { event.preventDefault(); void request('activate'); }}>
                    <div className="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-slate-800"><p className="font-semibold">{assignment.company}</p><p className="mt-1">{assignment.hub} · {kind === 'operator' ? 'Hub operator' : 'Rider'}</p></div>
                    <label className="block text-sm font-semibold text-slate-800">New password<input required type="password" autoComplete="new-password" minLength={12} maxLength={128} value={password} onChange={event => setPassword(event.target.value)} className={input} /></label>
                    <label className="block text-sm font-semibold text-slate-800">Confirm password<input required type="password" autoComplete="new-password" minLength={12} maxLength={128} value={confirmation} onChange={event => setConfirmation(event.target.value)} className={input} /></label>
                    <p className="text-xs leading-5 text-slate-600">12–128 characters with uppercase, lowercase, a number and a symbol.</p>
                    <label className="flex items-start gap-2 text-sm text-slate-700"><input required type="checkbox" checked={accepted} onChange={event => setAccepted(event.target.checked)} className="mt-1 rounded border-slate-300 text-[#E00D42]" />I accept this company and hub assignment.</label>
                    <button disabled={busy} className={button}>{busy ? 'Activating…' : 'Activate my work account'}</button>
                    <button type="button" disabled={busy} onClick={() => { setToken(''); setAssignment(null); setPassword(''); setConfirmation(''); setAccepted(false); setCode(''); }} className="block text-sm font-semibold text-[#C20836] underline">Request another code or correct my details</button>
                </form>}
            </>}
        </main>
    </GuestLayout>;
}
