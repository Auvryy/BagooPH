import { router } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Download, RefreshCw } from 'lucide-react';
import { IssueFrame, IssueBadge, IssueNotice, IssueBack, IssueError, RecordedParcel, issueButton, issuePrimary, issueInput, issueSurface, issueKind, issueDate, type IssueDetail } from '@/Components/HandoverUI';
import { AuthRequestError, AuthRequestScope, authRequest } from '@/utils/authRequests';
import { refreshPage } from '@/utils/accountRequests';

export default function Show({ issue, isOnline }: { issue: IssueDetail; isOnline: boolean }) {
    const [decision, setDecision] = useState('');
    const [notes, setNotes] = useState('');
    const [reviewed, setReviewed] = useState(false);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const token = useRef<string | null>(null);
    const scope = useRef(new AuthRequestScope());
    useEffect(() => () => scope.current.cancel(), []);
    useEffect(() => { token.current = null; setDecision(''); setNotes(''); setReviewed(false); setErrors({}); }, [issue.id, issue.version, issue.source_token]);

    async function submit(event: FormEvent) {
        event.preventDefault();
        const request = scope.current.begin();
        if (!request) return;
        token.current ??= crypto.randomUUID();
        setBusy(true); setErrors({});
        try {
            await authRequest(`/handover-issues/${issue.id}/review`, { decision, notes, evidence_reviewed: reviewed, expected_version: issue.version, source_token: issue.source_token, request_token: token.current }, request.signal);
            if (scope.current.current(request)) await refreshPage();
        } catch (error) {
            if (scope.current.current(request)) setErrors(error instanceof AuthRequestError ? { ...error.fields, request: error.message } : { request: error instanceof Error ? error.message : 'The review could not be confirmed. Retry the same decision.' });
        } finally {
            if (scope.current.current(request)) { setBusy(false); scope.current.finish(request); }
        }
    }

    return <IssueFrame title="Handover report" isOnline={isOnline}><div className="space-y-5">
        <div className="flex flex-wrap items-center justify-between gap-3"><IssueBack /><button type="button" className={issueButton} disabled={busy} onClick={() => router.reload()}><RefreshCw className="h-4 w-4" aria-hidden="true" />Refresh facts</button></div>
        <IssueNotice />
        <section className={issueSurface}><div className="flex flex-wrap items-center justify-between gap-3"><h2 className="text-lg font-bold">{issue.order_number}</h2><IssueBadge state={issue.state} /></div><p className="mt-2 break-words text-sm text-slate-600">{issue.reference} · Waybill {issue.tracking_number}</p><p className="mt-3 text-sm font-semibold">{issueKind[issue.kind]}</p><p className="mt-2 text-sm text-slate-600">{issue.reporter_name} · {issueDate(issue.created_at)} · Manila time</p><dl className="mt-5 grid gap-4 text-sm sm:grid-cols-2"><div><dt className="text-slate-500">Reported holder</dt><dd className="mt-1 break-words font-semibold">{issue.reported_holder}</dd></div><div><dt className="text-slate-500">Reported location</dt><dd className="mt-1 break-words font-semibold">{issue.reported_location}</dd></div></dl><p className="mt-4 whitespace-pre-wrap break-words text-sm leading-relaxed">{issue.notes}</p><a href={issue.evidence_url} className={`${issueButton} mt-5`}><Download className="h-4 w-4" aria-hidden="true" />Download evidence</a></section>
        {JSON.stringify(issue.original) !== JSON.stringify(issue.current) && <p role="status" className="rounded-[8px] border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">Recorded parcel history has changed since this report. Review the current facts below.</p>}
        <div className="grid gap-4 lg:grid-cols-2"><RecordedParcel snapshot={issue.original} title="Recorded when reported" /><RecordedParcel snapshot={issue.current} title="Recorded now" /></div>
        {issue.state === 'needs_recovery' && <section className={issueSurface}><h2 className="font-bold">Concern confirmed</h2><p className="mt-2 text-sm leading-relaxed text-slate-600">The report remains open. A corrective handover must use an authorized recovery process; confirmation here does not move the parcel or record a scan. If the existing recovery process does not cover this situation, your company must arrange a supervised review.</p></section>}
        {issue.can_review && <section className={issueSurface}><h2 className="font-bold">Independent review</h2><p className="mt-2 text-sm text-slate-600">Check the document and current parcel facts before deciding. Confirmation keeps the issue open for recovery.</p><form onSubmit={submit} className="mt-4 space-y-4"><fieldset disabled={busy} className="space-y-4"><label htmlFor="review-decision" className="block text-sm font-semibold">Decision<select id="review-decision" required value={decision} onChange={event => setDecision(event.target.value)} className={issueInput} aria-describedby="review-decision-error"><option value="">Choose a decision</option><option value="confirm">Confirm concern</option><option value="dismiss">Dismiss report</option></select><IssueError id="review-decision-error" message={errors.decision} /></label><label htmlFor="review-notes" className="block text-sm font-semibold">What did you verify?<textarea id="review-notes" required minLength={10} maxLength={1000} rows={4} value={notes} onChange={event => setNotes(event.target.value)} className={issueInput} aria-invalid={Boolean(errors.notes)} aria-describedby="review-notes-error" /><IssueError id="review-notes-error" message={errors.notes} /></label><label className="flex min-h-12 items-start gap-3 text-sm"><input type="checkbox" required checked={reviewed} onChange={event => setReviewed(event.target.checked)} className="mt-1 rounded border-slate-300 text-[#E00D42] focus:ring-[#E00D42]" /><span>I reviewed the supporting document and current parcel facts.</span></label><IssueError message={errors.evidence_reviewed} /></fieldset><IssueError message={errors.request} /><button type="submit" disabled={busy} className={issuePrimary}>{busy ? 'Saving review…' : 'Save review'}</button></form></section>}
        <section className={issueSurface}><h2 className="font-bold">Report history</h2><ol className="mt-4 divide-y divide-slate-300">{issue.events.map(event => <li key={event.id} className="py-4"><div className="flex flex-wrap items-center justify-between gap-2"><p className="text-sm font-semibold">{event.type === 'reported' ? 'Report submitted' : event.type === 'confirmed' ? 'Concern confirmed' : 'Report dismissed'}</p><time className="text-xs text-slate-500" dateTime={event.created_at}>{issueDate(event.created_at)}</time></div><p className="mt-2 text-sm text-slate-600">{event.actor_name} · {event.actor_role}</p><p className="mt-2 whitespace-pre-wrap break-words text-sm leading-relaxed">{event.notes}</p><p className="mt-2 text-xs text-slate-500">Recorded custody at this step: {event.parcel_snapshot.custody_label}</p></li>)}</ol></section>
    </div></IssueFrame>;
}
