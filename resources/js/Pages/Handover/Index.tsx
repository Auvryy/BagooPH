import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Plus, FileText } from 'lucide-react';
import { PageProps } from '@/types';
import UploadHint from '@/Components/UploadHint';
import { IssueFrame, IssueBadge, IssueNotice, IssueError, issueButton, issuePrimary, issueInput, issueSurface, issueKind, issueDate, type IssueSummary } from '@/Components/HandoverUI';
import { AuthRequestError, AuthRequestScope, authRequest } from '@/utils/authRequests';

interface Props {
    issues: { data: IssueSummary[]; current_page: number; last_page: number; total: number; prev_page_url: string | null; next_page_url: string | null };
    status: 'open' | 'recovered' | 'dismissed' | 'all'; canReport: boolean; isOnline: boolean;
}

export default function Index({ issues, status, canReport, isOnline }: Props) {
    const { auth } = usePage<PageProps>().props;
    const [showForm, setShowForm] = useState(false);
    const [barcode, setBarcode] = useState('');
    const [kind, setKind] = useState('missed_scan');
    const [holder, setHolder] = useState('');
    const [location, setLocation] = useState('');
    const [notes, setNotes] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const token = useRef<string | null>(null);
    const scope = useRef(new AuthRequestScope());
    useEffect(() => () => scope.current.cancel(), []);

    async function submit(event: FormEvent) {
        event.preventDefault();
        const request = scope.current.begin();
        if (!request) return;
        if (!file || file.size < 1 || file.size > 5 * 1024 * 1024 || !/\.(jpe?g|png|webp|pdf)$/i.test(file.name)) {
            setErrors({ evidence: 'Choose a non-empty JPG, PNG, WEBP or PDF no larger than 5 MB.' });
            scope.current.finish(request); return;
        }
        token.current ??= crypto.randomUUID();
        const data = new FormData();
        Object.entries({ barcode, kind, reported_holder: holder, reported_location: location, notes, request_token: token.current }).forEach(([key, value]) => data.append(key, value));
        data.append('evidence', file);
        setBusy(true); setErrors({});
        try {
            const reply = await authRequest('/handover-issues', data, request.signal);
            if (!scope.current.current(request)) return;
            if (typeof reply.id !== 'number' || !Number.isSafeInteger(reply.id) || reply.id < 1) throw new Error('The report response was incomplete. Retry the same report to confirm it was saved.');
            router.visit(`/handover-issues/${reply.id}`);
        } catch (error) {
            if (scope.current.current(request)) setErrors(error instanceof AuthRequestError ? { ...error.fields, request: error.message } : { request: error instanceof Error ? error.message : 'The report could not be confirmed. Retry the same report.' });
        } finally {
            if (scope.current.current(request)) { setBusy(false); scope.current.finish(request); }
        }
    }

    return <IssueFrame title="Handover issues" isOnline={isOnline}><div className="space-y-5">
        <IssueNotice />
        <div className="flex flex-wrap items-center justify-between gap-3"><p className="text-sm text-slate-600">{issues.total} {issues.total === 1 ? 'report' : 'reports'} in this view</p>{canReport && <button type="button" className={issuePrimary} aria-expanded={showForm} aria-controls="handover-report" disabled={busy} onClick={() => setShowForm(value => !value)}><Plus className="h-4 w-4" aria-hidden="true" />{showForm ? 'Close form' : 'Report an issue'}</button>}</div>
        {canReport && showForm && <section id="handover-report" className={issueSurface}><h2 className="text-lg font-bold">Report an unrecorded handover</h2><p className="mt-2 text-sm text-slate-600">Use the waybill on the actual parcel. Describe what you observed; supporting evidence helps your company review it.</p><form onSubmit={submit} className="mt-5 space-y-4"><fieldset disabled={busy} className="grid gap-4 sm:grid-cols-2">
            <label htmlFor="issue-barcode" className="text-sm font-semibold">Waybill number<input id="issue-barcode" required maxLength={255} value={barcode} onChange={event => setBarcode(event.target.value)} className={issueInput} aria-invalid={Boolean(errors.barcode)} aria-describedby="issue-barcode-error" /><IssueError id="issue-barcode-error" message={errors.barcode} /></label>
            <label htmlFor="issue-kind" className="text-sm font-semibold">What was missed?<select id="issue-kind" value={kind} onChange={event => setKind(event.target.value)} className={issueInput} aria-describedby="issue-kind-error"><option value="missed_scan">Handover scan</option>{auth.user?.role === 'seller' && <option value="missed_ready">Ready for Pickup</option>}</select><IssueError id="issue-kind-error" message={errors.kind} /></label>
            <label htmlFor="issue-holder" className="text-sm font-semibold">Who reportedly has the parcel?<input id="issue-holder" required minLength={2} maxLength={100} value={holder} onChange={event => setHolder(event.target.value)} className={issueInput} placeholder="Name or unknown holder" aria-invalid={Boolean(errors.reported_holder)} aria-describedby="issue-holder-error" /><IssueError id="issue-holder-error" message={errors.reported_holder} /></label>
            <label htmlFor="issue-location" className="text-sm font-semibold">Reported location<input id="issue-location" required minLength={5} maxLength={255} value={location} onChange={event => setLocation(event.target.value)} className={issueInput} aria-invalid={Boolean(errors.reported_location)} aria-describedby="issue-location-error" /><IssueError id="issue-location-error" message={errors.reported_location} /></label>
            <label htmlFor="issue-notes" className="text-sm font-semibold sm:col-span-2">What happened?<textarea id="issue-notes" required minLength={10} maxLength={1000} rows={4} value={notes} onChange={event => setNotes(event.target.value)} className={issueInput} aria-invalid={Boolean(errors.notes)} aria-describedby="issue-notes-error" /><IssueError id="issue-notes-error" message={errors.notes} /></label>
            <label htmlFor="issue-evidence" className="text-sm font-semibold sm:col-span-2">Supporting document<input id="issue-evidence" required type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" onChange={event => setFile(event.target.files?.[0] ?? null)} className={issueInput} aria-invalid={Boolean(errors.evidence)} aria-describedby="issue-upload-hint issue-evidence-error" /><UploadHint id="issue-upload-hint" /><IssueError id="issue-evidence-error" message={errors.evidence} /><span className="mt-1 block text-xs font-normal text-slate-500">Private to staff authorized to read this report.</span></label>
        </fieldset><IssueError message={errors.request} /><button type="submit" disabled={busy} className={issuePrimary}>{busy ? 'Saving report…' : 'Submit report'}</button></form></section>}
        <nav aria-label="Handover report filters" className={`flex gap-2 ${auth.user?.role === 'logistics' ? 'overflow-x-auto pb-1 [&>*]:shrink-0' : 'flex-wrap'}`}>{(['open', 'recovered', 'dismissed', 'all'] as const).map(value => <Link key={value} href={`/handover-issues?status=${value}`} preserveScroll className={status === value ? issuePrimary : issueButton} aria-current={status === value ? 'page' : undefined}>{value === 'open' ? 'Open' : value === 'dismissed' ? 'Dismissed' : value === 'recovered' ? 'Recovered' : 'All reports'}</Link>)}</nav>
        {issues.data.length === 0 ? <section className={`${issueSurface} flex min-h-60 flex-col items-center justify-center text-center`}><FileText className="h-8 w-8 text-slate-400" aria-hidden="true" /><h2 className="mt-3 text-lg font-bold">No handover reports here</h2><p className="mt-2 max-w-md text-sm text-slate-600">{canReport ? 'Report a missed step on a parcel you handle when there is an actual issue.' : 'Reports from involved staff appear here for independent review.'}</p></section>
            : <div className="grid gap-4 lg:grid-cols-2">{issues.data.map(issue => <article key={issue.id} className={issueSurface}><div className="flex flex-wrap items-center justify-between gap-3"><h2 className="font-bold">{issue.order_number}</h2><IssueBadge state={issue.state} /></div><p className="mt-3 break-words text-sm text-slate-600">Waybill: {issue.tracking_number}</p><p className="mt-2 text-sm font-semibold">{issueKind[issue.kind]}</p><p className="mt-2 text-sm text-slate-600">Reported by {issue.reporter_name}</p><div className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-300 pt-4"><p className="text-xs text-slate-500">{issueDate(issue.created_at)} · Manila time</p><Link href={`/handover-issues/${issue.id}`} className={issueButton}>View report<span className="sr-only"> for {issue.order_number}</span></Link></div></article>)}</div>}
        {issues.last_page > 1 && <nav aria-label="Handover report pages" className="flex flex-wrap items-center justify-between gap-3"><p className="text-sm text-slate-600">Page {issues.current_page} of {issues.last_page}</p><div className="flex gap-2">{issues.prev_page_url && <Link href={issues.prev_page_url} className={issueButton}>Previous</Link>}{issues.next_page_url && <Link href={issues.next_page_url} className={issueButton}>Next</Link>}</div></nav>}
    </div></IssueFrame>;
}
