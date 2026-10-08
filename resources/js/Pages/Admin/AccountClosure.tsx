import { refreshPage } from '@/utils/accountRequests';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { FormEvent, useState } from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { AccountClosureReview } from '@/types/accountClosure';

export default function AccountClosure({ closure, endpoint, usersUrl }: { closure: AccountClosureReview; endpoint: string; usersUrl: string }) {
    const [reason, setReason] = useState('');
    const [password, setPassword] = useState('');
    const [confirmed, setConfirmed] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<string[]>([]);
    const [conflict, setConflict] = useState(false);
    const [finished, setFinished] = useState(false);
    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setProcessing(true); setErrors([]);
        try {
            const result = await axios.post(endpoint, { reason, password, source_token: closure.source_token }, { headers: { Accept: 'application/json' } });
            setPassword(''); setFinished(true);
            if (result.data.signed_out) window.location.assign('/login');
            else await refreshPage();
        } catch (error) {
            setPassword('');
            if (axios.isAxiosError(error)) {
                setConflict(error.response?.status === 409);
                const details = error.response?.data?.errors as Record<string, string[]> | undefined;
                setErrors(details ? Object.values(details).flat() : [error.response?.data?.message ?? 'The closure could not be recorded.']);
            } else setErrors([error instanceof Error ? error.message : 'The closure could not be recorded. Try again.']);
        } finally { setProcessing(false); }
    };
    return <DashboardLayout title="Account closure review" subtitle="Resolve responsibilities and preserve supporting history" actions={<Link href={usersUrl} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">Back to accounts</Link>}>
        <Head title="Account closure — BagooPH" />
        <div className="max-w-4xl space-y-5">
            <section className="rounded-xl border border-slate-300 bg-white p-5">
                <h2 className="text-lg font-semibold">{closure.name}</h2>
                <p className="mt-1 text-sm capitalize text-slate-600">{closure.role} · Account #{closure.id}</p>
                <p className="mt-3 text-sm text-slate-700">{closure.retention_note}</p>
                <p className="mt-3 text-sm font-semibold">{closure.outcome === 'retained' ? 'This account will remain on record with sign-in closed.' : 'This account has no related records or stored files and can be removed after the final check.'}</p>
                {closure.closure && <div className="mt-4 border-t border-slate-300 pt-4 text-sm">
                    <p className="font-semibold">Closure #{closure.closure.id} · {closure.closure.outcome === 'retained' ? 'Account retained' : 'Unused account removed'}</p>
                    <p className="mt-2 whitespace-pre-line">{closure.closure.reason}</p>
                    <p className="mt-2 text-slate-600">{closure.closure.actor_name} · {new Date(closure.closure.closed_at).toLocaleString('en-PH', { timeZone: 'Asia/Manila' })}</p>
                </div>}
                {finished && !closure.closure && <p role="status" className="mt-3 text-sm text-emerald-700">Closure recorded.</p>}
            </section>
            {closure.blockers.length > 0 && <section className="rounded-xl border border-slate-300 bg-white p-5">
                <h2 className="font-semibold">Resolve before closing</h2>
                {closure.blockers.map((blocker, index) => <div key={index} className="mt-3 border-t border-slate-300 pt-3 text-sm"><p className="font-semibold">{blocker.message}</p><p className="mt-1 text-slate-600">{blocker.next}</p></div>)}
            </section>}
            {closure.work.length > 0 && <section className="rounded-xl border border-slate-300 bg-white p-5">
                <h2 className="font-semibold">Related orders and recorded amounts</h2>
                <div className="mt-3 max-h-72 space-y-3 overflow-y-auto">{closure.work.map(order => <p className="text-sm" key={order.id}>{order.number} · {order.status.replaceAll('_', ' ')} · ₱{Number(order.total).toLocaleString('en-PH', { minimumFractionDigits: 2 })} · {order.payment_method.toUpperCase()}</p>)}</div>
                <p className="mt-3 text-sm text-slate-600">These amounts describe the orders. They do not establish who holds cash or whether proceeds were paid.</p>
            </section>}
            {closure.allowed && !finished && <form onSubmit={submit} className="rounded-xl border border-slate-300 bg-white p-5">
                <h2 className="font-semibold">Confirm account closure</h2>
                <label className="mt-4 block text-sm font-medium">Reason<textarea required rows={3} maxLength={1000} value={reason} onChange={event => setReason(event.target.value)} className="mt-1 w-full rounded-lg border-slate-300" /></label>
                <label className="mt-4 block text-sm font-medium">Your admin password<input required type="password" autoComplete="current-password" value={password} onChange={event => setPassword(event.target.value)} className="mt-1 w-full rounded-lg border-slate-300" /></label>
                <label className="mt-4 flex items-start gap-3 text-sm"><input type="checkbox" checked={confirmed} onChange={event => setConfirmed(event.target.checked)} className="mt-1 rounded border-slate-300 text-[#E00D42]" />I reviewed this account's responsibilities and the proposed closure. Closing it will end account access.</label>
                {errors.map((error, index) => <p key={index} role="alert" className="mt-2 text-sm text-rose-700">{error}</p>)}
                {conflict && <button type="button" onClick={() => router.reload()} className="mt-3 rounded-lg border border-slate-300 px-3 py-2 text-sm">Reload current review</button>}
                <button disabled={processing || conflict || !confirmed || reason.trim().length < 5 || password.length === 0} className="mt-4 rounded-lg bg-[#E00D42] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{processing ? 'Recording...' : 'Close account'}</button>
            </form>}
        </div>
    </DashboardLayout>;
}
