import { Head, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import { cashInput, cashPrimary } from '@/Components/CodCashUI';

export default function CashLogin() {
    const form = useForm({ email: '', password: '' });
    return <GuestLayout title="Recorded cash handover" subtitle="Sign in to hand over cash already recorded against your account." showMarketplaceLink={false}><Head title="Recorded cash handover" /><form className="space-y-5" onSubmit={event => {event.preventDefault(); form.post('/cash-handover/sign-in', {onError:()=>form.reset('password')});}}><label className="block text-sm font-semibold" htmlFor="cash-email">Account email<input id="cash-email" type="email" autoComplete="username" required value={form.data.email} onChange={event=>form.setData('email',event.target.value)} className={cashInput} /></label><label className="block text-sm font-semibold" htmlFor="cash-password">Password<input id="cash-password" type="password" autoComplete="current-password" required value={form.data.password} onChange={event=>form.setData('password',event.target.value)} className={cashInput} /></label>{Object.values(form.errors).map(error=><p key={error} role="alert" className="text-sm text-red-700">{error}</p>)}<button disabled={form.processing} className={`${cashPrimary} w-full`}>{form.processing ? 'Signing in…' : 'Open recorded cash'}</button><p className="text-xs leading-relaxed text-slate-600">This handover access keeps existing account and assignment restrictions in place.</p></form></GuestLayout>;
}
