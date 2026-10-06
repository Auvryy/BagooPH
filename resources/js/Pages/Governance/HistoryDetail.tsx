import React from 'react';
import { Head, Link } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { GovernanceEvent, RecordedValues, governanceTime, recordLabel } from '@/Components/GovernanceRecord';

export default function HistoryDetail({ event }: { event: GovernanceEvent }) {
    return <DashboardLayout title="Recorded decision" subtitle={`${event.source_label} · ${recordLabel(event.subject.type)} #${event.subject.id}`} actions={<Link href="/governance-history" className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold">Back to history</Link>}>
        <Head title="Recorded decision — Bagoo Admin" />
        <div className="space-y-6">
            <section className="rounded-xl border border-slate-300 bg-white p-6">
                <h2 className="text-lg font-semibold text-slate-900">{recordLabel(event.action)}</h2>
                <p className="mt-2 whitespace-pre-wrap text-sm text-slate-700">{event.reason ?? 'No reason recorded'}</p>
                <dl className="mt-5 grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt className="text-slate-500">Recorded reviewer</dt><dd>{event.actor.name} · #{event.actor.id} · {event.actor.role ? recordLabel(event.actor.role) : 'Role not recorded'}</dd></div>
                    <div><dt className="text-slate-500">Philippine time</dt><dd>{governanceTime(event.occurred_at)}</dd></div>
                    <div><dt className="text-slate-500">Subject name at review</dt><dd>{event.subject.name ?? 'Name not recorded'}</dd></div>
                    <div><dt className="text-slate-500">Source record</dt><dd>{event.source_label} #{event.id}</dd></div>
                </dl>
                <p className="mt-5 text-xs text-slate-500">These values come from the retained decision. Missing values and links are shown as unrecorded.</p>
            </section>
            <div className="grid gap-6 lg:grid-cols-2">{[['Before decision', event.before], ['After decision', event.after]].map(([title, value]) => <section key={String(title)} className="rounded-xl border border-slate-300 bg-white p-6"><h2 className="mb-5 font-semibold text-slate-900">{String(title)}</h2><RecordedValues value={value} /></section>)}</div>
            <section className="rounded-xl border border-slate-300 bg-white p-6">
                <h2 className="font-semibold text-slate-900">Related records and evidence</h2>
                <div className="mt-4 space-y-3 text-sm">
                    {event.prior_decision ? <Link href={`/governance-history/${event.prior_decision.source}/${event.prior_decision.id}`} className="block font-semibold text-[#E00D42] hover:underline">View recorded prior decision</Link> : <p className="text-slate-500">No explicit prior decision link was recorded.</p>}
                    {event.context_url && <Link href={event.context_url} className="block font-semibold text-[#E00D42] hover:underline">Inspect current account context</Link>}
                    {event.documents.length ? <><p className="text-slate-600">Evidence access checks your current authority and the retained file’s integrity.</p><div className="flex flex-wrap gap-3">{event.documents.map((document) => <a key={document.kind} href={document.url} target="_blank" rel="noopener noreferrer" className="rounded-lg border border-slate-300 px-3 py-2 font-semibold text-[#E00D42]">View {recordLabel(document.kind)} evidence</a>)}</div></> : <p className="text-slate-500">No retained evidence link is available in this view.</p>}
                </div>
            </section>
        </div>
    </DashboardLayout>;
}
