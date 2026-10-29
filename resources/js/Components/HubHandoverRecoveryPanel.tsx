import { useState } from 'react';
import { RecoveryActionForm, RecoveryGrantSummary } from '@/Components/HandoverRecoveryPanel';
import { ScanLine, Truck } from 'lucide-react';
import { issueSurface, type IssueDetail } from '@/Components/HandoverUI';

export default function HubHandoverRecoveryPanel({ issue }: { issue: IssueDetail }) {
    const [sourceKey, setSourceKey] = useState('');
    const recovery = issue.hub_recovery;
    if (!recovery || (!recovery.grant && !recovery.options.length)) return null;
    const selected = recovery.options.find(source => source.key === sourceKey);
    const grant = recovery.grant;

    return <section className={`${issueSurface} space-y-4`}>
        <h2 className="font-bold">Supervised hub recovery</h2>
        <p className="text-sm leading-relaxed text-slate-600">Use the known physical parcel and its original facility or transport source. Corrective receipts record what is received now; they preserve the original list, scans and departure times. An unlisted receipt stays separate from the expected parcels.</p>
        {recovery.options.length > 0 && <fieldset className="space-y-2"><legend className="mb-2 text-sm font-semibold text-slate-700">Original handover source</legend>{recovery.options.map(source => <label key={source.key} className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3.5 ${selected?.key === source.key ? 'border-rose-300 bg-rose-50/50' : 'border-slate-200 bg-white'}`}><input required type="radio" name="recovery-source" checked={selected?.key === source.key} onChange={() => setSourceKey(source.key)} className="mt-1 border-slate-300 text-[#E00D42] focus:ring-[#E00D42]" /><span className="rounded-lg bg-slate-50 p-2 text-slate-400">{source.mode === 'hub_intake' ? <ScanLine className="h-4 w-4" /> : <Truck className="h-4 w-4" />}</span><span className="min-w-0"><span className="block text-sm font-semibold text-slate-800">{source.mode === 'hub_intake' ? 'Original hub intake' : source.mode === 'hub_failed_return' ? 'Recorded failed-delivery return' : source.mode === 'hub_manifest' ? 'Recorded transport manifest' : 'Existing authorized recovery'}</span><span className="mt-1 block break-words text-xs leading-5 text-slate-500">{source.label}</span></span></label>)}</fieldset>}
        {selected && <RecoveryActionForm key={`${selected.key}:${selected.source_token}:${issue.version}`}
            url={`/handover-issues/${issue.id}/authorize-hub-recovery`} title="Authorize actual hub recovery" button="Authorize recovery"
            description="Choose the known holder from the evidence. The original source fixes the receiving facility and route; independent authorization does not grant handler access."
            metadata={{ expected_version: issue.version, source_token: selected.source_token }}
            hubSource={{ mode: selected.mode, reference_id: selected.reference_id }} holders={selected.holder_options}
            confirmations={[{ name: 'holder_verified', label: 'I independently verified this exact parcel and its known current holder.' },
                { name: 'evidence_reviewed', label: 'I checked the original evidence, facility and recorded transport source.' }]} />}
        {selected && !selected.holder_options.length && <p role="status" className="text-sm text-slate-600">No eligible known holder is available for this source. Keep the parcel on hold while its actual custody is reviewed.</p>}
        {grant && <RecoveryGrantSummary grant={grant} />}
        {grant?.can_acknowledge && <RecoveryActionForm url={`/handover-issues/recovery/${grant.id}/acknowledge`} title="Confirm my current possession"
            button="Acknowledge possession" metadata={grant} barcode
            description="Scan the actual parcel you hold now. This acknowledgement is an observation; it does not create an old hub or transport scan."
            confirmations={[{ name: 'possession_verified', label: 'I physically hold this exact parcel now and will follow its authorized receiving route.' }]} />}
        {grant?.can_receive && <RecoveryActionForm url={`/handover-issues/recovery/${grant.id}/receive`} title="Record actual corrective receipt"
            button="Receive recovery parcel" metadata={grant} barcode
            description="Record receipt only after the named holder has handed you the actual parcel at this facility. Another eligible handler must receive it."
            confirmations={[{ name: 'possession_verified', label: 'I actually received this exact parcel from the named holder at the named hub.' }]} />}
        {grant?.can_withdraw && <RecoveryActionForm url={`/handover-issues/recovery/${grant.id}/withdraw`} title="Withdraw hub authorization"
            button="Withdraw authorization" metadata={{ expected_version: grant.expected_version, source_token: grant.withdraw_source_token ?? grant.source_token }}
            description="Explain why this authorization must no longer be used. Existing physical custody remains recorded and the parcel stays on hold." confirmations={[]} />}
    </section>;
}
