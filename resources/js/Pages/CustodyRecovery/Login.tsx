import { Head, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';

export default function RecoveryLogin() {
    const form = useForm({ email: '', password: '' });
    return <GuestLayout title="Authorized handover" subtitle="Sign in to return only the parcel covered by your current authorization." showMarketplaceLink={false}>
        <Head title="Authorized handover" />
        <form className="space-y-4" onSubmit={event => {event.preventDefault(); form.post('/custody-recovery/sign-in', {onError: () => form.reset('password')});}}>
            <label className="block text-sm font-semibold">Account email<input type="email" autoComplete="username" required value={form.data.email} onChange={event => form.setData('email',event.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" /></label>
            <label className="block text-sm font-semibold">Password<input type="password" autoComplete="current-password" required value={form.data.password} onChange={event => form.setData('password',event.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" /></label>
            {Object.values(form.errors).map(error => <p key={error} role="alert" className="text-sm text-red-700">{error}</p>)}
            <button disabled={form.processing} className="w-full rounded-lg bg-[#E00D42] px-4 py-2 font-semibold text-white disabled:opacity-50">Open my authorized handover</button>
        </form>
    </GuestLayout>;
}
