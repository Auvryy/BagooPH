import DashboardLayout from '@/Layouts/DashboardLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect } from 'react';

type Evidence = { actorName?: string; id?: number; reference: string; event_type?: string; reason_code?: string; attempt_number?: number; notes?: string; attempted_at?: string; created_at?: string; retry_at?: string };
type Detail = { kind: string; id: number; reference: string; reason: string; companyName: string | null; orderNumber: string | null; trackingNumber: string | null; status: string; responsibleName: string | null; blocker: string | null; canResolve: boolean; sourceToken: string; recoveryUrl: string | null; candidates: { id: number; name: string; role: string }[];
    state: { holderName: string; custody: { kind?: string } | null; parcel: { delivery_status?: string; order_status?: string } | null; source: { details: { status?: string; ready_at?: string; expires_at?: string; locked_until?: string; expired?: boolean; locked?: boolean } }; hubs: { id: number; name: string; is_active: boolean }[]; cash: { reference: string | null; holderName: string | null; amount_cents: number | null; evidence: string }; support: Record<string, string | number>; events: Record<string, Evidence[]> };
    history: { reference: string; action: string; reason: string; actor_name: string; responsible_name: string | null; created_at: string; supporting_evidence: Record<string, string | number> }[] };
const human = (value: string) => value.replaceAll('_', ' ').replaceAll('-', ' ');
const when = (value?: string) => value ? new Date(value).toLocaleString('en-PH', { timeZone: 'Asia/Manila' }) : 'Not recorded';

export default function ExceptionDetail({ exception: item, requestToken }: { exception: Detail; requestToken: string }) {
    const form = useForm({ action: 'assign', responsible_user_id: '', reason: '', source_token: item.sourceToken, request_token: requestToken });
    useEffect(() => { form.setData(data => ({ ...data, source_token: item.sourceToken, request_token: requestToken })); }, [item.sourceToken, requestToken]);
    const claim = item.state.source.details;
    const inputClass = 'mt-1.5 block w-full rounded-lg border border-zinc-200/90 bg-white px-3.5 py-2 text-xs font-medium text-[#121316] shadow-2xs focus:border-[#E00D42] focus:ring-2 focus:ring-[#E00D42]/15';

    return (
        <DashboardLayout title="Exception review" subtitle={item.orderNumber ?? item.reference}>
            <Head title="Exception review" />
            <div className="space-y-5">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <Link href="/exceptions" className="inline-flex items-center rounded-lg border border-zinc-200/90 bg-white px-3 py-1.5 text-xs font-semibold text-[#E00D42] shadow-2xs hover:bg-zinc-50">
                        Back to delivery exceptions
                    </Link>
                    <p className="text-xs text-zinc-500">
                        <span className="font-mono">{item.trackingNumber ?? item.reference}</span> · {item.companyName ?? 'Platform review'} · <span className="font-semibold capitalize text-[#121316]">{item.status}</span>
                    </p>
                </div>

                <p className="text-sm font-semibold text-[#121316]">{human(item.reason)}</p>

                <div className="grid gap-4 sm:grid-cols-2">
                    <section className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-5 shadow-xs">
                        <h2 className="text-sm font-semibold text-[#121316]">Parcel responsibility</h2>
                        <p className="mt-2 text-xs text-zinc-700">Custodian: <strong className="text-[#121316]">{item.state.holderName}</strong> ({human(item.state.custody?.kind ?? 'unverified')})</p>
                        <p className="mt-1 text-xs text-zinc-700">Parcel: {human(item.state.parcel?.delivery_status ?? 'unverified')}</p>
                        <p className="mt-1 text-xs text-zinc-700">Follow-up: {item.responsibleName ?? 'Awaiting assignment'}</p>
                        <p className="mt-2.5 text-xs leading-relaxed text-zinc-500">Assignment records who follows up. A physical scan records who receives the parcel.</p>
                    </section>

                    <section className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-5 shadow-xs">
                        <h2 className="text-sm font-semibold text-[#121316]">Cash evidence</h2>
                        <p className="mt-2 text-xs text-zinc-700">
                            {item.state.cash.evidence === 'not_cod'
                                ? 'This order does not use COD.'
                                : item.state.cash.holderName
                                ? `${item.state.cash.holderName} · ₱${((item.state.cash.amount_cents ?? 0) / 100).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`
                                : 'Cash holder is unverified.'}
                        </p>
                        {item.state.cash.reference && <p className="mt-1 font-mono text-xs text-zinc-500">Cash record: {item.state.cash.reference}</p>}
                        <p className="mt-2.5 text-xs leading-relaxed text-zinc-500">Cash reconciliation and seller settlement need their separate financial evidence. Closing this case records parcel recovery or holding only.</p>
                    </section>
                </div>

                <p className="text-xs text-zinc-600">
                    Facilities: <span className="font-medium text-[#121316]">{item.state.hubs.map(hub => `${hub.name}${hub.is_active ? '' : ' (restricted)'}`).join(' · ') || 'Unverified'}</span>
                </p>

                {item.kind === 'pickup' && (
                    <section className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-5 shadow-xs">
                        <h2 className="text-sm font-semibold text-[#121316]">Counter holding</h2>
                        <p className="mt-2 text-xs text-zinc-700">Ready: {when(claim.ready_at)} · Deadline: {when(claim.expires_at)} (Asia/Manila)</p>
                        <p className="mt-1 text-xs text-zinc-700">
                            {claim.expired ? 'Holding deadline reached' : 'Within holding period'} · {claim.locked ? `Verification locked until ${when(claim.locked_until)}` : human(claim.status ?? 'unverified')}
                        </p>
                        <p className="mt-2.5 text-xs leading-relaxed text-zinc-500">Only the buyer receives their private code. Counter release requires the authorized handler’s identity, code and cash checks.</p>
                    </section>
                )}

                {item.blocker && item.status === 'open' && (
                    <p className="rounded-xl border border-amber-200/90 bg-amber-50/80 p-4 text-xs sm:text-sm text-amber-900 shadow-2xs">{item.blocker}</p>
                )}

                {item.recoveryUrl && (
                    <Link href={item.recoveryUrl} className="inline-block rounded-lg border border-zinc-200/90 bg-white px-4 py-2 text-xs font-semibold text-[#E00D42] shadow-2xs hover:bg-zinc-50">
                        Review original hub handover authorization
                    </Link>
                )}

                <section className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-5 shadow-xs">
                    <h2 className="text-sm font-semibold text-[#121316]">Recorded evidence</h2>
                    {Object.entries(item.state.events).map(([group, events]) => events.length > 0 && (
                        <div key={group} className="mt-4">
                            <h3 className="text-xs font-semibold uppercase tracking-wider text-zinc-500 capitalize">{human(group)}</h3>
                            <ul className="mt-2 divide-y divide-zinc-100">
                                {events.map(event => (
                                    <li key={event.reference} className="py-2.5 text-xs">
                                        <p className="font-medium text-[#121316]">
                                            {event.actorName ?? 'Recorded actor'} · <span className="font-mono text-zinc-500">{event.reference}</span> · {human(event.event_type ?? event.reason_code ?? 'recorded attempt')}
                                            {event.attempt_number && ` · attempt ${event.attempt_number}`}
                                        </p>
                                        {event.notes && <p className="mt-1 text-zinc-600">{event.notes}</p>}
                                        <p className="mt-1 text-zinc-500">
                                            {when(event.attempted_at ?? event.created_at)}
                                            {event.retry_at && ` · Retry: ${when(event.retry_at)}`}
                                        </p>
                                        {group === 'attempts' && (
                                            <a href={`/exceptions/attempt/${event.id}/proof`} target="_blank" rel="noreferrer" className="mt-1 inline-block text-xs font-semibold text-[#E00D42] hover:underline">
                                                View retained proof
                                            </a>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </section>

                {item.status === 'open' && (
                    <form
                        onSubmit={e => {
                            e.preventDefault();
                            const data = { ...form.data };
                            if (data.action === 'resolve') delete (data as Partial<typeof data>).responsible_user_id;
                            form.transform(() => data);
                            form.post(`/exceptions/${item.kind}/${item.id}`, { preserveScroll: true, onSuccess: () => form.reset('reason') });
                        }}
                        className="space-y-4 overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-5 shadow-xs"
                    >
                        <h2 className="text-sm font-semibold text-[#121316]">Review decision</h2>
                        <label className="block text-xs font-semibold text-[#121316]">
                            Action
                            <select className={inputClass} value={form.data.action} onChange={e => form.setData('action', e.target.value)}>
                                <option value="assign">Assign follow-up responsibility</option>
                                <option value="resolve" disabled={!item.canResolve}>Resolve from recorded source evidence</option>
                            </select>
                        </label>
                        {form.data.action === 'assign' && (
                            <label className="block text-xs font-semibold text-[#121316]">
                                Responsible person
                                <select required className={inputClass} value={form.data.responsible_user_id} onChange={e => form.setData('responsible_user_id', e.target.value)}>
                                    <option value="">Choose a person</option>
                                    {item.candidates.map(person => (
                                        <option key={person.id} value={person.id}>{person.name} ({person.role})</option>
                                    ))}
                                </select>
                            </label>
                        )}
                        {form.data.action === 'resolve' && (
                            <p className="text-xs font-medium text-emerald-700">Supporting references: {Object.values(item.state.support).join(', ')}</p>
                        )}
                        <label className="block text-xs font-semibold text-[#121316]">
                            Reason
                            <textarea required minLength={5} maxLength={1000} className={inputClass} value={form.data.reason} onChange={e => form.setData('reason', e.target.value)} />
                        </label>
                        {Object.entries(form.errors).map(([key, error]) => <p key={key} className="text-xs text-rose-700">{error}</p>)}
                        <button disabled={form.processing} className="rounded-lg bg-[#E00D42] px-4 py-2 text-xs font-semibold text-white shadow-2xs transition hover:bg-[#C20836] disabled:opacity-50">
                            Record decision
                        </button>
                    </form>
                )}

                <section className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-5 shadow-xs">
                    <h2 className="text-sm font-semibold text-[#121316]">Decision history</h2>
                    {!item.history.length && <p className="mt-2 text-xs text-zinc-500">No oversight decision recorded yet.</p>}
                    <ul className="mt-2 divide-y divide-zinc-100">
                        {item.history.map(event => (
                            <li key={event.reference} className="py-3 text-xs">
                                <p className="font-medium text-[#121316]">{event.actor_name} · {human(event.action)} · <span className="font-normal text-zinc-500">{when(event.created_at)}</span></p>
                                <p className="mt-1 text-zinc-600">{event.reason}</p>
                                <p className="mt-1 font-mono text-zinc-500">
                                    {event.reference} · Follow-up: {event.responsible_name ?? 'Unassigned'}
                                    {Object.values(event.supporting_evidence).length > 0 && ` · ${Object.values(event.supporting_evidence).join(', ')}`}
                                </p>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </DashboardLayout>
    );
}
