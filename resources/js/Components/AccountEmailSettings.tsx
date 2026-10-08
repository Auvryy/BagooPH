import { useEffect, useState, type FormEvent } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import axios from 'axios';
import { type PageProps } from '@/types';
import { refreshPage, requestErrors } from '@/utils/accountRequests';

export type EmailSettings = {
    original: string;
    can_manage: boolean;
    addresses: { id: number; email: string; is_original: boolean; verified: boolean; preferred: boolean }[];
};

export default function AccountEmailSettings({ theme = 'buyer' }: { theme?: 'buyer' | 'seller' | 'courier' }) {
    const { emailSettings } = usePage<PageProps<{ emailSettings?: EmailSettings }>>().props;
    const form = useForm({ email: '', current_password: '', code: '' });
    const [sent, setSent] = useState(false);
    const [busy, setBusy] = useState('');
    const [result, setResult] = useState('');
    const [cooldown, setCooldown] = useState(0);
    useEffect(() => {
        if (cooldown <= 0) return;
        const timeout = window.setTimeout(() => setCooldown(current => Math.max(0, current - 1)), 1000);
        return () => window.clearTimeout(timeout);
    }, [cooldown]);
    if (!emailSettings) return null;
    const card = theme === 'courier' ? 'courier-panel rounded-[8px] bg-white p-4 shadow-sm sm:p-6' : theme === 'seller' ? 'rounded-2xl border border-slate-300 bg-white p-6 shadow-2xs' : 'rounded-2xl border border-slate-300 bg-white p-5 shadow-xs';
    const input = `mt-1 w-full border border-slate-300 bg-white p-2.5 text-sm focus:border-[#E00D42] focus:ring-[#E00D42] ${theme === 'courier' ? 'rounded-[8px]' : 'rounded-xl'}`;
    const action = async (kind: string, address?: number) => {
        if (busy) return;
        setBusy(kind); setResult(''); form.clearErrors();
        try {
            const response = kind === 'send' ? await axios.post('/account/emails/send', { email: form.data.email, current_password: form.data.current_password }, { headers: { Accept: 'application/json' } })
                : kind === 'confirm' ? await axios.post('/account/emails/confirm', form.data, { headers: { Accept: 'application/json' } })
                : kind === 'prefer' ? await axios.patch(`/account/emails/${address}/preferred`, { current_password: form.data.current_password }, { headers: { Accept: 'application/json' } })
                : await axios.delete(`/account/emails/${address}`, { data: { current_password: form.data.current_password }, headers: { Accept: 'application/json' } });
            if (kind === 'send') { setSent(true); setCooldown(response.data.cooldown ?? 60); }
            else { form.reset(); setSent(false); await refreshPage(); }
            setResult(response.data.message);
        } catch (error) {
            if (axios.isAxiosError(error) && error.response?.data?.cooldown) setCooldown(Math.ceil(error.response.data.cooldown));
            form.setError(requestErrors(error) as typeof form.errors);
        } finally { setBusy(''); }
    };
    const submit = (event: FormEvent) => { event.preventDefault(); void action(sent ? 'confirm' : 'send'); };
    return <section id="account-emails" className={`${card} scroll-mt-24 space-y-4`} data-settings-theme={theme} aria-busy={Boolean(busy)}>
        <div><h2 className="text-lg font-semibold text-slate-900">Email and recovery</h2><p className="mt-2 text-sm leading-relaxed text-slate-600">Keep your original sign-in email. Add and verify another address for contact and password recovery.</p></div>
        <ul className="divide-y divide-slate-200">
            {emailSettings.addresses.map(address => <li key={address.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                <div className="min-w-0"><p className="break-all text-sm font-semibold">{address.email}</p><p className="mt-1 text-xs text-slate-600">{address.is_original ? 'Original sign-in email · ' : 'Additional email · '}{address.verified ? 'Verified' : 'Verification required'}{address.preferred ? ' · Contact email' : ''}</p></div>
                {emailSettings.can_manage && <div className="flex flex-wrap gap-2">
                    {!address.preferred && address.verified && <button type="button" disabled={Boolean(busy) || !form.data.current_password} onClick={() => void action('prefer', address.id)} className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold disabled:opacity-50">Use for contact</button>}
                    {!address.is_original && <button type="button" disabled={Boolean(busy) || !form.data.current_password} onClick={() => void action('remove', address.id)} className="rounded-lg border border-rose-300 px-3 py-2 text-xs font-semibold text-rose-700 disabled:opacity-50">Remove</button>}
                </div>}
            </li>)}
        </ul>
        {emailSettings.can_manage && <form onSubmit={submit} className="space-y-4">
            <label className="block text-sm font-semibold">Current password<input type="password" value={form.data.current_password} onChange={event => form.setData('current_password', event.target.value)} autoComplete="current-password" disabled={Boolean(busy)} required className={input} /><span className="mt-1 block text-xs font-normal text-slate-600">Confirm your password to add, remove or choose a contact email.</span></label>
            <label className="block text-sm font-semibold">Additional email<input type="email" value={form.data.email} onChange={event => { form.setData('email', event.target.value); form.setData('code', ''); setSent(false); setResult(''); }} autoComplete="email" maxLength={255} disabled={Boolean(busy)} required className={input} /></label>
            {sent && <label className="block text-sm font-semibold">Verification code<input type="text" value={form.data.code} onChange={event => form.setData('code', event.target.value)} inputMode="numeric" autoComplete="one-time-code" pattern="[0-9]{6}" minLength={6} maxLength={6} required disabled={Boolean(busy)} className={input} /><span className="mt-1 block text-xs font-normal text-slate-600">Enter the six-digit code sent to {form.data.email}. It expires after ten minutes.</span></label>}
            <div className="flex flex-wrap gap-3"><button type="submit" disabled={Boolean(busy) || (!sent && cooldown > 0)} className="rounded-lg bg-[#E00D42] px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{busy ? 'Updating...' : sent ? 'Verify and add email' : cooldown > 0 ? `Send code in ${cooldown}s` : 'Send verification code'}</button>{sent && <button type="button" onClick={() => void action('send')} disabled={Boolean(busy) || cooldown > 0} className="rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold disabled:opacity-50">{cooldown > 0 ? `Resend in ${cooldown}s` : 'Resend code'}</button>}</div>
        </form>}
        {Object.entries(form.errors).map(([key, error]) => <p key={key} role="alert" className="text-sm text-rose-700">{error}</p>)}
        {result && <p role="status" className="text-sm text-emerald-700">{result}</p>}
    </section>;
}
