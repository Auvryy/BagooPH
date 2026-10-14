import { useRef, useState, type FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { Users } from 'lucide-react';
import CompanyRequestPagination from '@/Components/CompanyRequestPagination';
import useCourierRequestError from '@/hooks/useCourierRequestError';
import { courierDate } from '@/utils/courier';
import type { CompanyRiderIntake as Intake } from '@/types/riderCompany';

export default function CompanyRiderIntake({ workspace }: { workspace?: Intake | null }) {
    if (!workspace) return null;
    if (!workspace.available) return <section className="rounded-lg border border-slate-300 bg-white p-6 text-center"><h2 className="text-base font-bold text-slate-900">Rider requests unavailable</h2><p className="mt-2 text-sm text-slate-600">Please try again later.</p></section>;
    return <AvailableRiderIntake workspace={workspace} />;
}

function AvailableRiderIntake({ workspace }: { workspace: Extract<Intake, { available: true }> }) {
    const pending = useRef(false);
    const [unconfirmed, setUnconfirmed] = useState(false);
    const form = useForm({ enabled: workspace.enabled, expected_version: workspace.version, request_token: workspace.request_token });
    const accepting = workspace.enabled && workspace.history_available && workspace.has_eligible_hub;
    const intakeStatus = accepting ? 'Accepting requests' : workspace.enabled && workspace.history_available ? 'Facility needed' : 'Requests paused';
    useCourierRequestError(pending, (message) => { setUnconfirmed(true); form.setError('enabled', message); });
    const errors = form.errors as Record<string, string>;
    const save = (event: FormEvent) => {
        event.preventDefault();
        if (pending.current || !workspace.history_available || (!unconfirmed && form.data.enabled === workspace.enabled)) return;
        pending.current = true;
        form.put(route('hub.rider-request-intake'), {
            preserveScroll: true,
            onSuccess: (page) => {
                const saved = page.props.riderIntake as Intake | null;
                if (saved?.available) {
                    form.setData({ enabled: saved.enabled, expected_version: saved.version, request_token: saved.request_token });
                    setUnconfirmed(false);
                } else {
                    setUnconfirmed(true);
                    form.setError('enabled', 'Could not confirm the saved settings. Refresh the current requests before retrying.');
                }
            },
            onError: () => setUnconfirmed(false),
            onFinish: () => { pending.current = false; },
        });
    };
    return <section className="rounded-lg border border-slate-300 bg-white p-5 shadow-xs">
        <div className="flex flex-wrap items-start justify-between gap-3"><div><h2 className="text-base font-bold text-slate-900">Rider requests</h2><p className="mt-1 max-w-xl text-sm leading-relaxed text-slate-600">Let approved, unassigned riders ask to join your company. Each request shares a short profile; it does not place the rider at a facility.</p></div><span className={`rounded-md border px-3 py-1.5 text-xs font-semibold ${accepting ? 'border-emerald-300 bg-emerald-50 text-emerald-700' : 'border-slate-300 bg-slate-50 text-slate-600'}`}>{intakeStatus}</span></div>
        <form onSubmit={save} className="mt-4 space-y-3">
            <label className="flex min-h-12 cursor-pointer items-start gap-3 rounded-md border border-slate-300 bg-slate-50 p-3 text-sm"><input type="checkbox" checked={form.data.enabled} disabled={form.processing || unconfirmed || !workspace.history_available} onChange={(event) => { form.setData('enabled', event.target.checked); form.clearErrors(); }} className="mt-0.5 rounded border-slate-300 text-[#E00D42] focus:ring-[#E00D42]" /><span>Accept new rider requests</span></label>
            <p className="text-xs leading-relaxed text-slate-500">Pausing keeps saved requests and current riders. Your company appears in the rider directory only while intake is open and at least one facility is eligible.</p>
            {!workspace.has_eligible_hub && <p role="status" className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800">Your company needs an eligible facility before it can appear in the directory.</p>}
            {!workspace.history_available && <p role="alert" className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800">The saved intake decision needs review. New requests are unavailable until it is corrected.</p>}
            {Object.values(errors).map((message, index) => <p key={index} role="alert" className="text-sm text-rose-700">{message}</p>)}
            <div className="flex flex-wrap gap-2"><button type="submit" disabled={form.processing || !workspace.history_available || (!unconfirmed && form.data.enabled === workspace.enabled)} className="min-h-10 rounded-md bg-[#E00D42] px-4 py-2 text-sm font-semibold text-white hover:bg-[#C20836] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42] disabled:cursor-not-allowed disabled:opacity-50">{form.processing ? 'Saving…' : unconfirmed ? 'Retry same change' : 'Save request settings'}</button>{!form.processing && <Link href={route('hub.network')} className="inline-flex min-h-10 items-center rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42]">Refresh requests</Link>}</div>
            {unconfirmed && <p role="status" className="text-sm text-slate-600">The result is unconfirmed. Refresh the requests or retry this same change.</p>}
        </form>
        <div className="mt-5 border-t border-slate-200 pt-5">
            <h3 className="text-sm font-bold text-slate-900">Pending requests ({workspace.requests.total})</h3>
            {workspace.requests.data.length ? <div className="mt-3 grid gap-3 xl:grid-cols-2">{workspace.requests.data.map((request) => <article key={request.id} className="min-w-0 rounded-md border border-slate-300 bg-slate-50 p-4">
                <div className="flex flex-wrap justify-between gap-2"><h4 className="break-words text-sm font-bold text-slate-900">{request.shared_profile.name}</h4><span className="text-xs text-slate-500">Submitted {courierDate(request.submitted_at)}</span></div>
                <p className="mt-1 break-all text-xs text-slate-500">{request.reference}</p>
                <dl className="mt-3 grid gap-3 text-sm sm:grid-cols-2">{[
                    ['Email', request.shared_profile.email], ['Contact number', request.shared_profile.phone],
                    ['Locality', [request.shared_profile.city, request.shared_profile.province].filter(Boolean).join(', ')],
                    ['Vehicle type', request.shared_profile.vehicle_type?.replaceAll('_', ' ')],
                ].map(([label, value]) => <div key={label}><dt className="text-xs text-slate-500">{label}</dt><dd className="mt-1 break-words font-medium text-slate-800">{value || 'Not provided'}</dd></div>)}</dl>
                {request.note && <p className="mt-3 whitespace-pre-wrap break-words text-sm leading-relaxed text-slate-700">{request.note}</p>}
                {!request.rider_eligible && <p className="mt-3 text-xs font-medium text-amber-800">This rider is no longer eligible for a new placement. The original request is retained.</p>}
            </article>)}</div> : <div className="py-8 text-center"><Users className="mx-auto h-7 w-7 text-slate-400" aria-hidden="true" /><p className="mt-3 text-sm font-semibold text-slate-600">No pending rider requests</p><p className="mt-1 text-xs text-slate-500">New requests appear here after a rider submits them.</p></div>}
            <CompanyRequestPagination page={workspace.requests} label="Rider requests" busy={form.processing} />
        </div>
    </section>;
}
