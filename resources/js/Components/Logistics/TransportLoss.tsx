import { FormEvent, useCallback, useRef, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, FileText } from 'lucide-react';
import useRequestError from '@/hooks/useCourierRequestError';
import { PageProps } from '@/types';

export interface TransportLossRecord {
    reference: string;
    scope_kind: 'whole_transport' | 'unarrived_bags';
    verified: boolean;
    lost_bags_count: number;
    lost_bag_references: string[];
    created_at: string;
    reason: string | null;
    proof_url: string | null;
}

export interface TransportLossState {
    can_record: boolean;
    eligible_scope_kind: 'whole_transport' | 'unarrived_bags' | null;
    unarrived_bag_references: string[];
    record: TransportLossRecord | null;
}

interface Props {
    value: TransportLossState;
    manifestId: number;
    version: number;
    basePath: string;
}

const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
const ACCEPTED_EXTENSIONS = ['.jpg', '.jpeg', '.png', '.webp', '.pdf'];
const MAX_PROOF_BYTES = 5 * 1024 * 1024;

function isValidProofFile(proof: File | null): proof is File {
    if (!proof || proof.size <= 0 || proof.size > MAX_PROOF_BYTES) {
        return false;
    }
    if (proof.type && ACCEPTED_TYPES.includes(proof.type)) {
        return true;
    }
    const lower = (proof.name ?? '').toLowerCase();

    return ACCEPTED_EXTENSIONS.some(ext => lower.endsWith(ext));
}

function LossForm({ value, manifestId, version, basePath }: Props) {
    const form = useForm<{
        expected_version: number;
        request_token: string;
        reason: string;
        transport_loss_confirmed: boolean;
        proof: File | null;
    }>({
        expected_version: version,
        request_token: crypto.randomUUID(),
        reason: '',
        transport_loss_confirmed: false,
        proof: null,
    });
    const pending = useRef(false);
    const [failure, setFailure] = useState('');
    useRequestError(pending, useCallback(message => setFailure(message), []));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (pending.current || form.processing) return;
        const trimmedReason = form.data.reason.trim();
        if (!form.data.transport_loss_confirmed || trimmedReason.length < 10 || trimmedReason.length > 1000 || !isValidProofFile(form.data.proof)) {
            setFailure('Confirm the unarrived transport or sack loss, write 10–1,000 characters of investigation notes, and attach a JPG, PNG, WebP or PDF evidence file up to 5 MB.');
            return;
        }
        pending.current = true;
        setFailure('');
        form.transform(data => ({
            expected_version: data.expected_version,
            request_token: data.request_token,
            reason: data.reason.trim(),
            transport_loss_confirmed: data.transport_loss_confirmed,
            proof: data.proof,
        }));
        form.post(`${basePath}/${manifestId}/whole-transport-loss`, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                form.setData('proof', null);
                form.setData('reason', '');
                form.setData('transport_loss_confirmed', false);
            },
            onFinish: () => {
                pending.current = false;
            },
        });
    };

    const isUnarrivedBags = value.eligible_scope_kind === 'unarrived_bags';
    const field = 'mt-2 w-full rounded-lg border border-slate-300 bg-white text-sm text-slate-900 focus:border-[#E00D42] focus:ring-[#E00D42]';

    return (
        <form onSubmit={submit} className="space-y-4" aria-busy={form.processing || undefined}>
            <p className="text-sm leading-6 text-slate-600">
                {isUnarrivedBags
                    ? 'Record independent administrator confirmation for unopened Mother Bags or return sacks that never arrived on this partially arrived transport.'
                    : 'Record independent administrator confirmation when the original transport never arrived at the destination hub and all parcels already carry loss or recovery accountability.'}
            </p>
            {value.unarrived_bag_references.length > 0 && (
                <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-950">
                    Unopened unarrived sacks covered: {value.unarrived_bag_references.join(', ')}. Physical bag tags stay locked.
                </p>
            )}
            <label className="flex items-start gap-3 text-sm leading-6">
                <input
                    required
                    type="checkbox"
                    disabled={form.processing}
                    checked={form.data.transport_loss_confirmed}
                    onChange={event => form.setData('transport_loss_confirmed', event.target.checked)}
                    className="mt-1 rounded border-slate-300 text-[#E00D42]"
                />
                I independently confirmed this transport or these unopened sacks never arrived and no physical arrival or bag opening is fabricated.
            </label>
            <label className="block text-sm font-semibold">
                Independent investigation details
                <textarea
                    required
                    minLength={10}
                    maxLength={1000}
                    rows={3}
                    autoComplete="off"
                    disabled={form.processing}
                    value={form.data.reason}
                    onChange={event => form.setData('reason', event.target.value)}
                    className={field}
                />
            </label>
            <label className="block text-sm font-semibold">
                Private loss evidence (JPG, PNG, WebP or PDF up to 5 MB)
                <input
                    required
                    type="file"
                    accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf"
                    disabled={form.processing}
                    onChange={event => form.setData('proof', event.target.files?.[0] ?? null)}
                    className="mt-2 block w-full text-sm text-slate-700 file:mr-4 file:rounded-lg file:border-0 file:bg-rose-50 file:px-4 file:py-2 file:text-xs file:font-semibold file:text-[#C20836] hover:file:bg-rose-100"
                />
            </label>
            {failure && <p role="alert" className="text-sm text-rose-700">{failure}</p>}
            {Object.entries(form.errors).map(([key, error]) => (
                <p key={key} role="alert" className="text-sm text-rose-700">{error}</p>
            ))}
            <button
                disabled={form.processing}
                className="rounded-lg bg-[#E00D42] px-4 py-3 text-sm font-bold text-white hover:bg-[#C20836] disabled:opacity-50"
            >
                {form.processing ? 'Recording transport loss…' : 'Confirm whole-transport loss'}
            </button>
        </form>
    );
}

export default function TransportLoss(props: Props) {
    const { auth } = usePage<PageProps>().props;
    if (!props.value.record && !props.value.can_record) return null;
    const record = props.value.record;

    return (
        <section className="space-y-4 rounded-lg border border-amber-300 bg-white p-5 shadow-sm">
            <h3 className="flex items-center gap-2 font-bold text-slate-900">
                <AlertTriangle className="h-5 w-5 text-amber-600" />
                {record?.scope_kind === 'unarrived_bags' ? 'Unarrived sack loss accountability' : 'Whole-transport loss accountability'}
            </h3>
            {record ? (
                <div role="status" className="space-y-2 text-sm leading-6 text-slate-700">
                    <p>
                        Confirmed · {record.reference} · {record.scope_kind === 'unarrived_bags' ? 'Unarrived bags confirmed lost' : 'Whole transport confirmed lost'}. No physical arrival or opening was fabricated.
                    </p>
                    {record.lost_bag_references.length > 0 && (
                        <p className="text-xs text-slate-600">
                            Unarrived sacks locked as lost ({record.lost_bags_count}): {record.lost_bag_references.join(', ')}.
                        </p>
                    )}
                    {record.reason && (
                        <p className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                            {record.reason}
                        </p>
                    )}
                    {record.proof_url && (
                        <a
                            href={record.proof_url}
                            className="inline-flex items-center gap-1.5 text-xs font-semibold text-[#C20836] hover:underline"
                        >
                            <FileText className="h-4 w-4" />
                            Download private loss evidence
                        </a>
                    )}
                </div>
            ) : (
                <LossForm key={`${auth.user?.id ?? ''}:${props.manifestId}:${props.version}`} {...props} />
            )}
        </section>
    );
}
