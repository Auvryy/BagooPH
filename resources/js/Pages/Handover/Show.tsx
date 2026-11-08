import { router } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Download, RefreshCw } from 'lucide-react';
import { IssueFrame, IssueBadge, IssueNotice, IssueBack, IssueError, RecordedParcel, issueButton, issueKind, issueDate, issueEvent, type IssueDetail } from '@/Components/HandoverUI';
import HandoverRecoveryPanel from '@/Components/HandoverRecoveryPanel';
import HubHandoverRecoveryPanel from '@/Components/HubHandoverRecoveryPanel';
import { AuthRequestError, AuthRequestScope, authRequest } from '@/utils/authRequests';
import { refreshPage } from '@/utils/accountRequests';

const fieldClass = 'mt-1.5 w-full rounded-lg border border-zinc-300 bg-white px-3.5 py-2 text-sm text-[#121316] shadow-2xs focus:border-[#E00D42] focus:outline-none focus:ring-2 focus:ring-[#E00D42]/15 transition-colors';

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

    return (
        <IssueFrame title="Handover report" isOnline={isOnline}>
            <div className="space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <IssueBack />
                    <button type="button" className={issueButton} disabled={busy} onClick={() => router.reload()}>
                        <RefreshCw className="h-4 w-4" aria-hidden="true" />
                        Refresh facts
                    </button>
                </div>

                <IssueNotice />

                {/* Main Issue Details Card */}
                <section className="rounded-xl border border-zinc-200/90 bg-white shadow-xs overflow-hidden">
                    <header className="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5">
                        <h2 className="rounded-md border border-rose-200 bg-rose-50 px-2 py-0.5 tabular-nums text-xs font-bold text-[#E00D42]">
                            {issue.order_number}
                        </h2>
                        <IssueBadge state={issue.state} />
                    </header>
                    <div className="p-5 sm:p-6">
                        <p className="break-words text-xs tabular-nums text-zinc-600">{issue.reference} · Waybill {issue.tracking_number}</p>
                        <p className="mt-3 text-sm font-bold text-[#121316]">{issueKind[issue.kind]}</p>
                        <p className="mt-1.5 text-xs text-zinc-500">{issue.reporter_name} · {issueDate(issue.created_at)} · Manila time</p>

                        <dl className="mt-5 grid gap-4 rounded-xl border border-zinc-200/80 bg-zinc-50/30 p-4 text-sm sm:grid-cols-2">
                            <div>
                                <dt className="text-xs font-medium text-zinc-500">Reported holder</dt>
                                <dd className="mt-1 break-words font-bold text-[#121316]">{issue.reported_holder}</dd>
                            </div>
                            <div>
                                <dt className="text-xs font-medium text-zinc-500">Reported location</dt>
                                <dd className="mt-1 break-words font-bold text-[#121316]">{issue.reported_location}</dd>
                            </div>
                        </dl>

                        <p className="mt-4 whitespace-pre-wrap break-words text-sm leading-relaxed text-zinc-700">{issue.notes}</p>
                        <a href={issue.evidence_url} className={`${issueButton} mt-5 inline-flex`}>
                            <Download className="h-4 w-4" aria-hidden="true" />
                            Download evidence
                        </a>
                    </div>
                </section>

                {JSON.stringify(issue.original) !== JSON.stringify(issue.current) && (
                    <p role="status" className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950 shadow-2xs">
                        Recorded parcel history has changed since this report. Review the current facts below.
                    </p>
                )}

                <div className="grid gap-4 lg:grid-cols-2">
                    <RecordedParcel snapshot={issue.original} title="Recorded when reported" />
                    <RecordedParcel snapshot={issue.current} title="Recorded now" />
                </div>

                <HandoverRecoveryPanel issue={issue} />
                <HubHandoverRecoveryPanel issue={issue} />

                {/* Independent Review Card */}
                {issue.can_review && (
                    <section className="rounded-xl border border-zinc-200/90 bg-white shadow-xs overflow-hidden">
                        <header className="border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5">
                            <h2 className="text-sm font-bold text-[#121316]">Independent review</h2>
                            <p className="mt-0.5 text-xs text-zinc-500">
                                Check the document and current parcel facts before deciding. Confirmation keeps the issue open for recovery.
                            </p>
                        </header>
                        <form onSubmit={submit} className="p-5 sm:p-6 space-y-4">
                            <fieldset disabled={busy} className="space-y-4">
                                <label htmlFor="review-decision" className="block text-xs font-bold text-[#121316]">
                                    Decision
                                    <select
                                        id="review-decision"
                                        required
                                        value={decision}
                                        onChange={event => setDecision(event.target.value)}
                                        className={fieldClass}
                                        aria-describedby="review-decision-error"
                                    >
                                        <option value="">Choose a decision</option>
                                        <option value="confirm">Confirm concern</option>
                                        <option value="dismiss">Dismiss report</option>
                                    </select>
                                    <IssueError id="review-decision-error" message={errors.decision} />
                                </label>
                                <label htmlFor="review-notes" className="block text-xs font-bold text-[#121316]">
                                    What did you verify?
                                    <textarea
                                        id="review-notes"
                                        required
                                        minLength={10}
                                        maxLength={1000}
                                        rows={4}
                                        value={notes}
                                        onChange={event => setNotes(event.target.value)}
                                        className={fieldClass}
                                        aria-invalid={Boolean(errors.notes)}
                                        aria-describedby="review-notes-error"
                                    />
                                    <IssueError id="review-notes-error" message={errors.notes} />
                                </label>
                                <label className="flex min-h-12 items-start gap-3 text-sm text-zinc-700">
                                    <input
                                        type="checkbox"
                                        required
                                        checked={reviewed}
                                        onChange={event => setReviewed(event.target.checked)}
                                        className="mt-1 rounded border-zinc-300 text-[#E00D42] focus:ring-[#E00D42]"
                                    />
                                    <span>I reviewed the supporting document and current parcel facts.</span>
                                </label>
                                <IssueError message={errors.evidence_reviewed} />
                            </fieldset>
                            <IssueError message={errors.request} />
                            <button
                                type="submit"
                                disabled={busy}
                                className="rounded-lg bg-[#E00D42] hover:bg-[#C20836] px-5 py-2.5 text-xs font-bold text-white shadow-xs transition disabled:opacity-50"
                            >
                                {busy ? 'Saving review…' : 'Save review'}
                            </button>
                        </form>
                    </section>
                )}

                {/* Report History Card */}
                <section className="rounded-xl border border-zinc-200/90 bg-white shadow-xs overflow-hidden">
                    <header className="border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5">
                        <h2 className="text-sm font-bold text-[#121316]">Report history</h2>
                    </header>
                    <div className="p-5 sm:p-6">
                        <ol className="divide-y divide-zinc-100">
                            {issue.events.map(event => (
                                <li key={event.id} className="py-4 first:pt-0 last:pb-0">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <p className="text-sm font-bold text-[#121316]">{issueEvent[event.type]}</p>
                                        <time className="text-xs text-zinc-500" dateTime={event.created_at}>
                                            {issueDate(event.created_at)}
                                        </time>
                                    </div>
                                    <p className="mt-1.5 text-xs text-zinc-500">
                                        {event.actor_name} · {event.actor_role}
                                    </p>
                                    <p className="mt-2 whitespace-pre-wrap break-words text-sm leading-relaxed text-zinc-700">{event.notes}</p>
                                    <p className="mt-2 text-xs text-zinc-500">
                                        Recorded custody at this step: {event.parcel_snapshot.custody_label}
                                    </p>
                                </li>
                            ))}
                        </ol>
                    </div>
                </section>
            </div>
        </IssueFrame>
    );
}
