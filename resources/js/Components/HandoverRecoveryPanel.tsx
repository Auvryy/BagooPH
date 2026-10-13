import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import WaybillCamera from '@/Components/WaybillCamera';
import { IssueError, issueDate, issueInput, issuePrimary, issueSurface, type IssueDetail, type RecoveryGrant } from '@/Components/HandoverUI';
import { AuthRequestError, AuthRequestScope, authRequest } from '@/utils/authRequests';
import { refreshPage } from '@/utils/accountRequests';

interface ActionProps {
    url: string; title: string; description: string; button: string;
    metadata: { expected_version: number; source_token: string };
    confirmations: Array<{ name: string; label: string }>;
    barcode?: boolean; holders?: Array<{ id: number; name: string; status: string }>;
}

export function RecoveryActionForm({ url, title, description, button, metadata, confirmations, barcode = false, holders }: ActionProps) {
    const id = useId();
    const [waybill, setWaybill] = useState('');
    const [notes, setNotes] = useState('');
    const [holder, setHolder] = useState('');
    const [checked, setChecked] = useState<Record<string, boolean>>({});
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const token = useRef<string | null>(null);
    const scope = useRef(new AuthRequestScope());
    useEffect(() => () => scope.current.cancel(), []);
    useEffect(() => {
        token.current = null; setWaybill(''); setNotes(''); setHolder(''); setChecked({}); setErrors({});
    }, [url, metadata.expected_version, metadata.source_token]);

    async function submit(event: FormEvent) {
        event.preventDefault();
        const request = scope.current.begin();
        if (!request) return;
        token.current ??= crypto.randomUUID();
        setBusy(true); setErrors({});
        try {
            await authRequest(url, { expected_version: metadata.expected_version, source_token: metadata.source_token, notes, request_token: token.current, ...checked,
                ...(barcode ? { barcode: waybill } : {}), ...(holders ? { holder_id: holder } : {}) }, request.signal);
            if (scope.current.current(request)) await refreshPage();
        } catch (error) {
            if (scope.current.current(request)) {
                if (error instanceof AuthRequestError && error.status === 422) token.current = null;
                setErrors(error instanceof AuthRequestError ? { ...error.fields, request: error.message }
                    : { request: 'The result could not be confirmed. Retry the same action or refresh the current facts.' });
            }
        } finally {
            if (scope.current.current(request)) { setBusy(false); scope.current.finish(request); }
        }
    }

    return <form onSubmit={submit} className="space-y-4 rounded-[8px] border border-slate-300 p-4">
        <h3 className="font-bold">{title}</h3><p className="text-sm leading-relaxed text-slate-600">{description}</p>
        <fieldset disabled={busy} className="space-y-4">
            {holders && <label htmlFor={`${id}-holder`} className="block text-sm font-semibold">Verified current holder
                <select id={`${id}-holder`} required value={holder} onChange={event => setHolder(event.target.value)} className={issueInput} aria-invalid={Boolean(errors.holder_id)} aria-describedby={`${id}-holder-error`}>
                    <option value="">Choose the evidenced holder</option>
                    {holders.map(person => <option key={person.id} value={person.id}>{person.name}{person.status !== 'active' ? ' · Restricted handover access' : ''}</option>)}
                </select><IssueError id={`${id}-holder-error`} message={errors.holder_id} />
            </label>}
            {barcode && <div className="space-y-2"><label htmlFor={`${id}-waybill`} className="block text-sm font-semibold">Actual parcel waybill
                <input id={`${id}-waybill`} required maxLength={255} autoComplete="off" value={waybill} onChange={event => setWaybill(event.target.value)} className={issueInput} aria-invalid={Boolean(errors.barcode)} aria-describedby={`${id}-waybill-error`} />
                <IssueError id={`${id}-waybill-error`} message={errors.barcode} />
            </label><WaybillCamera onScan={setWaybill} disabled={busy} /></div>}
            <label htmlFor={`${id}-notes`} className="block text-sm font-semibold">What did you verify?
                <textarea id={`${id}-notes`} required minLength={10} maxLength={1000} rows={3} value={notes} onChange={event => setNotes(event.target.value)} className={issueInput} aria-invalid={Boolean(errors.notes)} aria-describedby={`${id}-notes-error`} />
                <IssueError id={`${id}-notes-error`} message={errors.notes} />
            </label>
            {confirmations.map(item => <div key={item.name}><label className="flex min-h-12 items-start gap-3 text-sm leading-relaxed">
                <input type="checkbox" required checked={checked[item.name] ?? false} onChange={event => setChecked(value => ({ ...value, [item.name]: event.target.checked }))} className="mt-1 rounded border-slate-300 text-[#E00D42] focus:ring-[#E00D42]" />
                <span>{item.label}</span>
            </label><IssueError message={errors[item.name]} /></div>)}
        </fieldset>
        <IssueError message={errors.request} /><button type="submit" disabled={busy || (holders !== undefined && holders.length === 0)} className={issuePrimary}>{busy ? 'Saving recovery…' : button}</button>
    </form>;
}

export function RecoveryGrantSummary({ grant }: { grant: RecoveryGrant }) {
    return <div className="space-y-2 text-sm leading-relaxed">
        <p className="break-words font-semibold">{grant.reference}</p>
        <p>Named holder: <span className="font-semibold">{grant.holder_name}</span></p>
        <p>Receiving hub: <span className="font-semibold">{grant.hub.name}</span>{grant.hub.address ? ` · ${grant.hub.address}` : ''}</p>
        <p>Authorization ends {issueDate(grant.expires_at)} · Manila time</p>
        {grant.blocker && !grant.closed && <p role="status" className="rounded-[8px] border border-amber-300 bg-amber-50 p-3 text-amber-950">{grant.blocker}</p>}
        {grant.acknowledged && !grant.closed && <p className="font-semibold">Current possession acknowledged. The named hub must still scan its actual receipt.</p>}
    </div>;
}

export default function HandoverRecoveryPanel({ issue }: { issue: IssueDetail }) {
    const recovery = issue.recovery;
    if (issue.state === 'recovered') return <section className={issueSurface}><h2 className="font-bold">Recovery received</h2><p className="mt-2 text-sm leading-relaxed text-slate-600">The named hub recorded its actual receipt and the hold ended. Original history is retained. Normal route processing can continue.</p></section>;
    if (!recovery.held) return null;
    const metadata = { expected_version: issue.version, source_token: recovery.source_token };
    const grant = recovery.grant;
    return <section className={`${issueSurface} space-y-4`}>
        <h2 className="font-bold">Parcel on hold</h2>
        <p role="status" className="rounded-[8px] border border-amber-300 bg-amber-50 p-4 text-sm leading-relaxed text-amber-950">The report remains open. Ordinary parcel actions are paused until the named holder confirms possession and the correct hub scans its actual receipt. Expiry or withdrawal keeps the hold in place.</p>
        {recovery.fulfillment_required && <p className="text-sm leading-relaxed text-slate-600">The owning approved seller must first confirm the omitted acceptance and packing steps from the actual facts.</p>}
        {recovery.can_confirm_fulfillment && <RecoveryActionForm url={`/handover-issues/${issue.id}/confirm-fulfillment`} title="Confirm seller fulfillment" button="Confirm fulfillment now" metadata={metadata} barcode
            description="Confirm that this order was accepted, packed and labelled. Missing commercial steps are recorded now; this does not claim an old packing or handover scan."
            confirmations={[{ name: 'fulfillment_verified', label: 'I verified that this exact order was accepted, packed and labelled before its unrecorded handover.' }]} />}
        {recovery.can_authorize && <RecoveryActionForm url={`/handover-issues/${issue.id}/authorize-recovery`} title="Authorize supervised recovery" button="Authorize recovery" metadata={metadata} holders={recovery.holder_options}
            description="Choose the known physical holder from the evidence. The existing assignment and route fix the receiving hub. A different eligible person must review; unknown possession stays unresolved."
            confirmations={[{ name: 'holder_verified', label: 'I independently verified the named holder and current physical parcel.' }, { name: 'evidence_reviewed', label: 'I reviewed the original supporting document and current parcel facts.' }]} />}
        {!recovery.mode && !recovery.fulfillment_required && <p className="text-sm leading-relaxed text-slate-600">This source needs a separate supervised review. Current recovery covers seller/pickup mistakes and an assigned rider missing departure from the destination hub.</p>}
        {grant && <RecoveryGrantSummary grant={grant} />}
        {grant?.can_acknowledge && <RecoveryActionForm url={`/handover-issues/recovery/${grant.id}/acknowledge`} title="Confirm my current possession" button="Acknowledge possession" metadata={grant} barcode
            description="Scan the waybill on the parcel you physically hold now. This records the current fact, keeps the hold and requires its return to the named hub."
            confirmations={[{ name: 'possession_verified', label: 'I physically hold this exact parcel now and will hand it to the named receiving hub.' }]} />}
        {grant?.can_receive && <RecoveryActionForm url={`/handover-issues/recovery/${grant.id}/receive`} title="Record actual hub receipt" button="Receive recovery parcel" metadata={grant} barcode
            description="Scan only after the named holder has handed you the physical parcel. This receipt records hub custody and ends the hold."
            confirmations={[{ name: 'possession_verified', label: 'I actually received this exact parcel from the named holder at this hub.' }]} />}
        {grant?.can_withdraw && <RecoveryActionForm url={`/handover-issues/recovery/${grant.id}/withdraw`} title="Withdraw authorization" button="Withdraw authorization" metadata={grant} description="Record why this authorization should no longer be used. The parcel stays on hold; a fresh review is needed before another authorization." confirmations={[]} />}
    </section>;
}
