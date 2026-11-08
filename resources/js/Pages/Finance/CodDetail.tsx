import { useRef, useState, type FormEvent } from 'react';
import { Link, router } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, ArrowRight, Building2, CheckCircle2, Clock3, Receipt, ShieldCheck, Wallet } from 'lucide-react';
import { CourierDialog, CourierFieldError } from '@/Components/CourierUI';
import { CashBadge, CashFrame, CashJourney, cashButton, cashDate, cashEventLabel, cashInput, cashMoney, cashPrimary, type CashDetail, type CashMode } from '@/Components/CodCashUI';
import { cashInputCents, cashInputPesos } from '@/utils/codCash';
import useCourierRequestError from '@/hooks/useCourierRequestError';
import { type CourierScope } from '@/utils/courier';

interface Props { account: CashDetail; base: string; mode: CashMode; isOnline: boolean; can_reconcile: boolean; requestToken: string; scope?: CourierScope }
type Action = 'offer' | 'receive' | 'cancel' | 'adjust' | 'reconcile' | 'authorize-recovery';
interface Target { action: Action; holderId?: number; sourceReference?: string }
const titles: Record<Action, string> = { offer: 'Offer a cash handover', receive: 'Confirm counted cash', cancel: 'Cancel this handover offer', adjust: 'Review the cash difference', reconcile: 'Reconcile COD at the platform', 'authorize-recovery': 'Authorize a recovery handover' };

function CashActionDialog({ target, account, base, requestToken, onClose }: { target: Target; account: CashDetail; base: string; requestToken: string; onClose: () => void }) {
    const [holderId, setHolderId] = useState(String(target.holderId ?? account.holders.find(holder => holder.can_offer)?.user_id ?? ''));
    const [recipientId, setRecipientId] = useState('');
    const holder = account.holders.find(person => person.user_id === Number(holderId));
    const difference = target.sourceReference ? account.discrepancies[target.sourceReference] : null;
    const [amount, setAmount] = useState(cashInputPesos(difference ? Math.abs(difference.difference_cents) : holder?.amount_cents ?? 0));
    const [received, setReceived] = useState('');
    const [reference, setReference] = useState('');
    const [reason, setReason] = useState('');
    const [confirmed, setConfirmed] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const pending = useRef(false);
    useCourierRequestError(pending, message => setErrors({ cash: message }));
    const recovery = holder?.stage === 'rider' && account.recovery?.holder_id === holder.user_id && new Date(account.recovery.expires_at).getTime() > Date.now();
    const recipients = target.action === 'authorize-recovery' || holder?.stage === 'hub' || recovery ? account.recipients.platform : account.recipients.hub;
    const due = account.pending?.amount_cents ?? 0;
    const counted = cashInputCents(received);
    const delta = counted === null ? null : counted - due;
    const needsReason = target.action === 'receive' ? delta !== null && delta !== 0 : ['cancel', 'adjust', 'reconcile', 'authorize-recovery'].includes(target.action);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (pending.current) return;
        const data: Record<string, string | number | boolean> = { request_token: requestToken, expected_version: account.version };
        if (target.action === 'offer' || target.action === 'authorize-recovery') { data.holder_id = holderId; data.recipient_id = recipientId; }
        if (target.action === 'offer') data.amount = amount;
        if (target.action === 'receive' || target.action === 'cancel') data.offer_reference = account.pending?.reference ?? '';
        if (target.action === 'receive') { data.received_amount = received; data.receipt_confirmed = confirmed; }
        if (target.action === 'adjust' && difference) { data.source_reference = target.sourceReference!; data.amount = amount; data.adjustment_type = difference.difference_cents < 0 ? 'shortage_recovered' : 'overage_separated'; }
        if (!['cancel', 'authorize-recovery'].includes(target.action)) data.evidence_reference = reference;
        if (needsReason || reason) data.reason = reason;
        pending.current = true; setBusy(true); setErrors({});
        router.post(`${base}/${account.id}/${target.action}`, data, { preserveScroll: true, onSuccess: onClose, onError: setErrors, onFinish: () => { pending.current = false; setBusy(false); } });
    };
    const original = account.history.find(event => event.reference === target.sourceReference);
    const usedRecoveries = Object.values(account.discrepancies).map(item => item.recovery_evidence);
    const compatibleRecoveries = difference && original ? account.history.filter(event => event.event_type === 'handover_received'
        && event.sequence > original.sequence && event.from_user_id === difference.holder_id && event.to_user_id === difference.recipient_id
        && event.from_stage === difference.from_stage && event.to_stage === difference.to_stage
        && event.discrepancy_cents === 0 && event.amount_cents === Math.abs(difference.difference_cents) && !usedRecoveries.includes(event.reference)) : [];
    return (
        <CourierDialog open title={titles[target.action]} onClose={() => { if (!busy) onClose(); }} busy={busy}>
            <form onSubmit={submit} className="space-y-4">
                <div className="rounded-lg border border-zinc-200/90 bg-zinc-50/70 p-3">
                    <p className="text-sm font-bold text-[#121316]">{account.order_number}</p>
                    <p className="mt-1 text-xs text-zinc-500">{account.hub_name} · Original due {cashMoney(account.expected_cents)}</p>
                </div>
                {target.action === 'offer' && <p className="text-sm leading-relaxed text-zinc-600">Choose the person receiving the actual cash. Your responsibility stays recorded until they confirm their count.</p>}
                {target.action === 'authorize-recovery' && <p className="text-sm leading-relaxed text-zinc-600">The original hub is restricted. This authorization covers only the named rider’s recorded cash and lasts 24 hours. It does not restore operational access.</p>}
                {(target.action === 'offer' || target.action === 'authorize-recovery') && (
                    <>
                        <label htmlFor="cash-holder" className="block text-sm font-semibold text-[#121316]">
                            Recorded cash holder
                            <select
                                id="cash-holder"
                                required
                                value={holderId}
                                disabled={busy}
                                onChange={event => {
                                    setHolderId(event.target.value);
                                    setRecipientId('');
                                    const next = account.holders.find(person => person.user_id === Number(event.target.value));
                                    setAmount(cashInputPesos(next?.amount_cents ?? 0));
                                }}
                                className={cashInput}
                            >
                                <option value="">Choose a recorded holder</option>
                                {account.holders.filter(person => target.action === 'authorize-recovery' ? person.stage === 'rider' : person.can_offer).map(person => (
                                    <option key={person.user_id} value={person.user_id}>{person.name} · {cashMoney(person.amount_cents)}</option>
                                ))}
                            </select>
                            <CourierFieldError message={errors.holder_id} />
                        </label>
                        <label htmlFor="cash-recipient" className="block text-sm font-semibold text-[#121316]">
                            Receiving person
                            <select id="cash-recipient" required value={recipientId} disabled={busy} onChange={event => setRecipientId(event.target.value)} className={cashInput}>
                                <option value="">Choose the receiving person</option>
                                {recipients.filter(person => person.id !== Number(holderId) && (!recovery || person.id === account.recovery?.recipient_id)).map(person => (
                                    <option key={person.id} value={person.id}>{person.name}</option>
                                ))}
                            </select>
                            <CourierFieldError message={errors.recipient_id} />
                        </label>
                        {recipients.length === 0 && <p role="status" className="text-sm text-amber-800">No eligible recipient is available. Ask Platform Admin to review this cash responsibility.</p>}
                    </>
                )}
                {target.action === 'offer' && (
                    <label className="block text-sm font-semibold text-[#121316]" htmlFor="cash-offer-amount">
                        Cash being handed over
                        <input id="cash-offer-amount" required inputMode="decimal" autoComplete="off" pattern="(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,2})?" value={amount} disabled={busy} onChange={event => setAmount(event.target.value)} className={cashInput} />
                        <p className="mt-2 text-xs font-normal text-zinc-500">Partial handovers keep the remaining responsibility with the original holder.</p>
                        <CourierFieldError message={errors.amount} />
                    </label>
                )}
                {target.action === 'receive' && (
                    <>
                        <p className="text-sm leading-relaxed text-zinc-600">
                            Count the cash received from <strong>{account.pending?.holder_name}</strong>. Expected handover: <strong>{cashMoney(due)}</strong>.
                        </p>
                        <label className="block text-sm font-semibold text-[#121316]" htmlFor="cash-counted">
                            Actual cash counted
                            <input id="cash-counted" required inputMode="decimal" autoComplete="off" pattern="(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,2})?" value={received} disabled={busy} onChange={event => setReceived(event.target.value)} placeholder="0.00" className={cashInput} />
                            <CourierFieldError message={errors.received_amount} />
                        </label>
                        {delta !== null && (
                            <p role="status" className={`rounded-lg border p-3 text-sm ${delta ? 'border-amber-300 bg-amber-50 text-amber-900' : 'border-emerald-300 bg-emerald-50 text-emerald-800'}`}>
                                {delta === 0 ? 'Your count matches the offered amount.' : `${delta < 0 ? 'Shortage' : 'Overage'}: ${cashMoney(Math.abs(delta))}. Explain the difference below.`}
                            </p>
                        )}
                        <label className="flex items-start gap-3 rounded-lg border border-zinc-200/90 p-3 text-sm text-[#121316]">
                            <input type="checkbox" required disabled={busy} checked={confirmed} onChange={event => setConfirmed(event.target.checked)} className="mt-0.5 rounded border-zinc-300 text-[#E00D42] focus:ring-[#E00D42]" />
                            <span>I physically received and counted this cash.</span>
                        </label>
                        <CourierFieldError message={errors.receipt_confirmed} />
                    </>
                )}
                {target.action === 'adjust' && difference && (
                    <>
                        <p className="text-sm leading-relaxed text-zinc-600">
                            {difference.difference_cents < 0
                                ? `Shortage of ${cashMoney(Math.abs(difference.difference_cents))}. Choose the later verified receipt of the recovered amount. A review cannot create missing money.`
                                : `Overage of ${cashMoney(difference.difference_cents)}. The extra cash stays separately recorded with its receiving holder and does not increase this order’s COD.`}
                        </p>
                        {difference.difference_cents < 0 && (
                            <label className="block text-sm font-semibold text-[#121316]" htmlFor="cash-recovery-receipt">
                                Verified recovery receipt
                                <select id="cash-recovery-receipt" required value={reference} disabled={busy} onChange={event => setReference(event.target.value)} className={cashInput}>
                                    <option value="">Choose the actual recovery receipt</option>
                                    {compatibleRecoveries.map(event => (
                                        <option key={event.reference} value={event.reference}>
                                            {event.from_name} → {event.to_name} · {cashMoney(event.amount_cents)} · receipt {event.sequence}
                                        </option>
                                    ))}
                                </select>
                                {compatibleRecoveries.length === 0 && <p className="mt-2 text-xs font-normal text-amber-800">The missing cash must be handed over and received before this review.</p>}
                            </label>
                        )}
                    </>
                )}
                {target.action === 'cancel' && <p className="text-sm leading-relaxed text-zinc-600">Only the unconfirmed offer is cancelled. The original cash holder remains accountable for the same amount.</p>}
                {target.action === 'reconcile' && (
                    <p className="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm leading-relaxed text-emerald-900">
                        Confirm the original collection and recorded platform receipts for <strong>{cashMoney(account.expected_cents)}</strong>. This decision records COD reconciliation. Seller payout requires its separate review.
                    </p>
                )}
                {!['cancel', 'authorize-recovery'].includes(target.action) && !(target.action === 'adjust' && difference && difference.difference_cents < 0) && (
                    <label className="block text-sm font-semibold text-[#121316]" htmlFor="cash-evidence-reference">
                        {target.action === 'receive' ? 'Receipt reference' : 'Evidence reference'}
                        <input id="cash-evidence-reference" required maxLength={120} value={reference} disabled={busy} onChange={event => setReference(event.target.value)} className={cashInput} placeholder="Reference on the cash receipt" />
                        <CourierFieldError message={errors.evidence_reference} />
                    </label>
                )}
                {(needsReason || target.action === 'receive') && (
                    <label className="block text-sm font-semibold text-[#121316]" htmlFor="cash-reason">
                        {target.action === 'receive' ? 'Explanation of any difference' : 'Review explanation'}
                        <textarea id="cash-reason" rows={3} required={needsReason} minLength={needsReason ? 10 : undefined} maxLength={1000} value={reason} disabled={busy} onChange={event => setReason(event.target.value)} className={cashInput} />
                        <CourierFieldError message={errors.reason} />
                    </label>
                )}
                <CourierFieldError message={errors.cash || errors.expected_version || errors.offer_reference || errors.source_reference || errors.request_token} />
                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button type="button" disabled={busy} className={cashButton} onClick={onClose}>Cancel</button>
                    <button type="submit" disabled={busy || (target.action === 'receive' && !confirmed)} className={cashPrimary}>
                        {busy ? 'Saving…' : target.action === 'receive' ? 'Confirm counted receipt' : target.action === 'reconcile' ? 'Confirm reconciliation' : target.action === 'offer' ? 'Record handover offer' : 'Save review'}
                    </button>
                </div>
            </form>
        </CourierDialog>
    );
}

export default function CodDetail({ account, base, mode, isOnline, can_reconcile, requestToken, scope }: Props) {
    const [target, setTarget] = useState<Target | null>(null);
    const differences = Object.entries(account.discrepancies);
    const pending = account.pending;
    const editable = account.status !== 'reconciled';
    return (
        <CashFrame mode={mode} isOnline={isOnline} scope={scope} title="Cash collection and handovers" subtitle="Review the original amount, the receiving people, and every recorded cash step.">
            <div className="space-y-5 pb-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Link href={base} className={cashButton}><ArrowLeft className="h-4 w-4" aria-hidden="true" />Cash records</Link>
                    <button className={cashButton} onClick={() => router.reload({ only: ['account', 'requestToken'] })}>Refresh records</button>
                </div>

                <section className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white shadow-xs">
                    <div className="flex flex-wrap items-start justify-between gap-4 border-b border-zinc-100 bg-zinc-50/40 px-5 py-4 sm:px-6">
                        <div>
                            <p className="text-[11px] font-semibold uppercase tracking-wider text-zinc-500">
                                {account.source_kind === 'rider_collection' ? 'Doorstep collection' : account.source_kind === 'counter_collection' ? 'Counter collection' : 'Older record review'}
                            </p>
                            <h2 className="mt-1 text-lg font-bold text-[#121316]">{account.order_number}</h2>
                            <p className="mt-0.5 break-all font-mono text-xs text-zinc-500">{account.tracking_number}</p>
                        </div>
                        <CashBadge status={account.status} />
                    </div>
                    <div className="p-5 sm:p-6">
                        <div className="mb-5 flex flex-wrap items-end justify-between gap-4 border-b border-zinc-100 pb-5">
                            <div>
                                <p className="text-xs font-semibold text-zinc-500">Original COD due</p>
                                <p className="mt-1 font-mono text-3xl font-bold tracking-tight tabular-nums text-[#121316] sm:text-4xl">{cashMoney(account.expected_cents)}</p>
                            </div>
                            <div className="flex items-center gap-2.5 rounded-lg border border-zinc-200/80 bg-zinc-50/70 px-3.5 py-2.5 text-sm">
                                <Building2 className="h-4 w-4 shrink-0 text-zinc-500" aria-hidden="true" />
                                <div>
                                    <p className="text-xs font-semibold text-[#121316]">{account.hub_name}</p>
                                    <p className="mt-0.5 text-[11px] text-zinc-500">{account.company_name}</p>
                                </div>
                            </div>
                        </div>
                        <CashJourney account={account} />
                    </div>
                </section>

                <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_320px]">
                    <div className="space-y-5">
                        {pending && (
                            <section className="overflow-hidden rounded-xl border border-zinc-200/90 border-l-4 border-l-[#E00D42] bg-white p-5 shadow-xs">
                                <div className="flex items-center gap-2">
                                    <Clock3 className="h-4 w-4 text-[#E00D42]" aria-hidden="true" />
                                    <h2 className="text-sm font-semibold text-[#121316]">Handover awaiting receipt</h2>
                                </div>
                                <div className="mt-4 grid gap-3 sm:grid-cols-[1fr_auto_1fr]">
                                    <div className="rounded-lg border border-zinc-200/80 bg-zinc-50/70 p-3">
                                        <p className="text-xs text-zinc-500">Recorded source</p>
                                        <p className="mt-1 text-sm font-semibold text-[#121316]">{pending.holder_name}</p>
                                    </div>
                                    <ArrowRight className="hidden self-center text-zinc-400 sm:block" aria-hidden="true" />
                                    <div className="rounded-lg border border-rose-200/80 bg-rose-50/70 p-3">
                                        <p className="text-xs text-zinc-600">Receiving person</p>
                                        <p className="mt-1 text-sm font-semibold text-[#121316]">{pending.recipient_name}</p>
                                    </div>
                                </div>
                                <p className="mt-4 font-mono text-2xl font-bold tabular-nums text-[#121316]">{cashMoney(pending.amount_cents)}</p>
                                <p className="mt-1.5 text-xs leading-relaxed text-zinc-500">The recipient must count and confirm the cash before the recorded holder changes.</p>
                                <div className="mt-4 flex flex-wrap gap-2">
                                    {pending.can_receive && <button className={cashPrimary} onClick={() => setTarget({ action: 'receive' })}><Receipt className="h-4 w-4" aria-hidden="true" />Confirm counted cash</button>}
                                    {pending.can_cancel && <button className={cashButton} onClick={() => setTarget({ action: 'cancel' })}>Cancel unconfirmed offer</button>}
                                </div>
                            </section>
                        )}

                        {account.status === 'unverified' && (
                            <section className="rounded-xl border border-amber-200/90 bg-amber-50/80 p-5 text-sm leading-relaxed text-amber-900 shadow-2xs">
                                <h2 className="font-bold">Collection evidence is still missing</h2>
                                <p className="mt-1.5 text-xs leading-relaxed">This review records what was found. It does not create a cash holder, backdate a collection, or permit reconciliation from a paid label.</p>
                            </section>
                        )}

                        <section className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white shadow-xs">
                            <div className="flex items-center gap-2 border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5">
                                <Wallet className="h-4 w-4 text-zinc-500" aria-hidden="true" />
                                <h2 className="text-sm font-semibold text-[#121316]">Current cash responsibilities</h2>
                            </div>
                            <div className="p-5">
                                <div className="space-y-3">
                                    {account.holders.map(holder => (
                                        <div key={`${holder.stage}-${holder.user_id}`} className="rounded-lg border border-zinc-200/90 bg-zinc-50/30 p-4">
                                            <div className="flex flex-wrap justify-between gap-3">
                                                <div>
                                                    <p className="text-sm font-semibold text-[#121316]">{holder.name}</p>
                                                    <p className="mt-0.5 text-xs text-zinc-500">
                                                        {holder.stage === 'rider' ? 'Collecting rider' : holder.stage === 'hub' ? 'Hub cash holder' : 'Platform cash recipient'}
                                                        {holder.restricted ? ' · Account restricted' : ''}
                                                    </p>
                                                </div>
                                                <p className="font-mono text-lg font-bold tabular-nums text-[#121316]">{cashMoney(holder.amount_cents)}</p>
                                            </div>
                                            {holder.can_offer && editable && !pending && (
                                                <button className={`${cashButton} mt-3`} onClick={() => setTarget({ action: 'offer', holderId: holder.user_id })}>
                                                    Hand over cash<ArrowRight className="h-4 w-4" aria-hidden="true" />
                                                </button>
                                            )}
                                            {account.can_authorize_recovery && holder.stage === 'rider' && editable && !pending && (
                                                <button className={`${cashButton} mt-3`} onClick={() => setTarget({ action: 'authorize-recovery', holderId: holder.user_id })}>
                                                    Authorize platform recovery
                                                </button>
                                            )}
                                        </div>
                                    ))}
                                    {account.holders.length === 0 && <p className="text-xs text-zinc-500">No cash holder can be established from the available sources.</p>}
                                </div>
                                {account.recovery && (
                                    <p className="mt-4 text-xs leading-relaxed text-zinc-500">
                                        Recorded recovery authorization expires {cashDate(account.recovery.expires_at)} (Asia/Manila). Normal account restrictions remain in place.
                                    </p>
                                )}
                            </div>
                        </section>

                        {differences.length > 0 && (
                            <section className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white shadow-xs">
                                <div className="flex items-center gap-2 border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5">
                                    <AlertTriangle className="h-4 w-4 text-amber-700" aria-hidden="true" />
                                    <h2 className="text-sm font-semibold text-[#121316]">Cash differences and reviews</h2>
                                </div>
                                <ul className="space-y-3 p-5">
                                    {differences.map(([reference, difference]) => (
                                        <li key={reference} className={`rounded-lg border p-4 ${difference.resolved_by ? 'border-zinc-200/90 bg-zinc-50/60' : 'border-amber-200 bg-amber-50/80'}`}>
                                            <div className="flex flex-wrap justify-between gap-2">
                                                <p className="text-sm font-semibold text-[#121316]">
                                                    {difference.difference_cents < 0 ? 'Shortage' : 'Overage'} · <span className="font-mono">{cashMoney(Math.abs(difference.difference_cents))}</span>
                                                </p>
                                                <p className="text-xs font-semibold text-zinc-600">{difference.resolved_by ? 'Reviewed' : 'Review required'}</p>
                                            </div>
                                            <p className="mt-1.5 break-all font-mono text-xs text-zinc-500">Original receipt: {reference}</p>
                                            {difference.recovery_evidence && <p className="mt-1 break-all font-mono text-xs text-zinc-500">Recovered cash receipt: {difference.recovery_evidence}</p>}
                                            {!difference.resolved_by && can_reconcile && editable && !pending && (
                                                <button className={`${cashButton} mt-3`} onClick={() => setTarget({ action: 'adjust', sourceReference: reference })}>
                                                    Review original difference
                                                </button>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}

                        <section className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white shadow-xs">
                            <div className="border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5">
                                <h2 className="text-sm font-semibold text-[#121316]">Recorded cash history</h2>
                                <p className="mt-0.5 text-xs text-zinc-500">Original receipts remain available after every handover and review.</p>
                            </div>
                            <ol className="space-y-5 p-5">
                                {account.history.map(event => (
                                    <li key={event.reference} className="relative border-l-2 border-zinc-200 pl-5">
                                        <span className={`absolute -left-[7px] top-1 h-3 w-3 rounded-full ${event.event_type === 'platform_reconciled' ? 'bg-emerald-600' : 'bg-[#E00D42]'}`} aria-hidden="true" />
                                        <div className="flex flex-wrap items-start justify-between gap-2">
                                            <div>
                                                <h3 className="text-sm font-semibold text-[#121316]">{cashEventLabel[event.event_type] ?? 'Cash review'}</h3>
                                                <p className="mt-0.5 text-xs text-zinc-500">{event.actor_name} · {cashDate(event.created_at)} (Asia/Manila)</p>
                                            </div>
                                            {event.amount_cents !== 0 && (
                                                <p className="font-mono text-sm font-bold tabular-nums text-[#121316]">
                                                    {event.event_type === 'adjustment' && event.amount_cents > 0 ? '+' : ''}{cashMoney(event.amount_cents)}
                                                </p>
                                            )}
                                        </div>
                                        {event.from_name && event.to_name && <p className="mt-1.5 text-xs font-medium text-zinc-700">{event.from_name} → {event.to_name}</p>}
                                        {event.event_type === 'handover_received' && (
                                            <p className="mt-1.5 text-xs text-zinc-500">
                                                Expected {cashMoney(event.expected_cents)} · Counted {cashMoney(event.received_cents)}
                                                {event.discrepancy_cents !== 0 && ` · ${event.discrepancy_cents < 0 ? 'Short' : 'Extra'} ${cashMoney(Math.abs(event.discrepancy_cents))}`}
                                            </p>
                                        )}
                                        {event.reason && <p className="mt-1.5 text-xs leading-relaxed text-zinc-600">{event.reason}</p>}
                                        {event.evidence_reference && <p className="mt-1.5 break-words font-mono text-xs text-zinc-500">Evidence: {event.evidence_reference}</p>}
                                        <details className="mt-2 text-xs text-zinc-500">
                                            <summary className="w-fit cursor-pointer py-1 font-semibold text-[#C20836]">Receipt reference</summary>
                                            <p className="mt-1 break-all font-mono">{event.reference}</p>
                                            {event.source_reference && <p className="mt-1.5 break-all font-mono">Linked original: {event.source_reference}</p>}
                                        </details>
                                    </li>
                                ))}
                            </ol>
                        </section>
                    </div>

                    <aside className="space-y-5 xl:sticky xl:top-4">
                        <section className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white shadow-xs">
                            <div className="border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5">
                                <h2 className="text-sm font-semibold text-[#121316]">Saved order amounts</h2>
                            </div>
                            <div className="p-5">
                                <dl className="space-y-2.5 text-xs">
                                    <div className="flex justify-between gap-2">
                                        <dt className="text-zinc-500">Product subtotal</dt>
                                        <dd className="font-mono font-semibold tabular-nums text-[#121316]">{cashMoney(account.product_subtotal_cents)}</dd>
                                    </div>
                                    <div className="flex justify-between gap-2">
                                        <dt className="text-zinc-500">Order discount</dt>
                                        <dd className="font-mono font-semibold tabular-nums text-[#121316]">−{cashMoney(account.discount_cents)}</dd>
                                    </div>
                                    <div className="flex justify-between gap-2">
                                        <dt className="text-zinc-500">Shipping</dt>
                                        <dd className="font-mono font-semibold tabular-nums text-[#121316]">{cashMoney(account.shipping_cents)}</dd>
                                    </div>
                                    <div className="flex justify-between gap-2 border-t border-zinc-100 pt-2.5 text-sm">
                                        <dt className="font-bold text-[#121316]">COD due</dt>
                                        <dd className="font-mono font-bold tabular-nums text-[#121316]">{cashMoney(account.expected_cents)}</dd>
                                    </div>
                                </dl>
                                <p className="mt-4 text-xs leading-relaxed text-zinc-500">Shipping stays separate from product proceeds. These amounts do not represent a seller or rider payout.</p>
                            </div>
                        </section>

                        <section className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white shadow-xs">
                            <div className="flex items-center gap-2 border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5">
                                <ShieldCheck className={`h-4 w-4 ${account.status === 'reconciled' ? 'text-emerald-600' : 'text-[#E00D42]'}`} aria-hidden="true" />
                                <h2 className="text-sm font-semibold text-[#121316]">Platform reconciliation</h2>
                            </div>
                            <div className="p-5">
                                {account.status === 'reconciled' ? (
                                    <>
                                        <p className="font-mono text-2xl font-bold tabular-nums text-emerald-700">{cashMoney(account.reconciled_cents)}</p>
                                        <p className="mt-2 text-xs leading-relaxed text-zinc-500">The platform review is recorded. Seller payout and buyer completion remain separate.</p>
                                    </>
                                ) : (
                                    <>
                                        <p className="text-xs leading-relaxed text-zinc-500">The full saved COD amount needs confirmed platform receipts and reviewed cash differences.</p>
                                        {can_reconcile && account.status === 'at_platform' && !pending ? (
                                            <button className={`${cashPrimary} mt-4 w-full`} onClick={() => setTarget({ action: 'reconcile' })}>
                                                <CheckCircle2 className="h-4 w-4" aria-hidden="true" />Review reconciliation
                                            </button>
                                        ) : (
                                            <p className="mt-4 rounded-lg border border-zinc-200/80 bg-zinc-50/70 p-3 text-xs leading-relaxed text-zinc-500">
                                                {can_reconcile ? 'Complete cash handovers and resolve differences before this review.' : 'Platform Admin records the reconciliation after the required receipts.'}
                                            </p>
                                        )}
                                    </>
                                )}
                            </div>
                        </section>

                        {account.excess_cents > 0 && (
                            <section className="rounded-xl border border-amber-200/90 bg-amber-50/80 p-5 shadow-2xs">
                                <h2 className="text-xs font-bold uppercase tracking-wider text-amber-900">Separate unallocated cash</h2>
                                <p className="mt-2 font-mono text-xl font-bold tabular-nums text-amber-900">{cashMoney(account.excess_cents)}</p>
                                <ul className="mt-3 space-y-1.5 text-xs text-amber-900">
                                    {account.excess_holders.map((holder, index) => <li key={index}>{holder.name} · <span className="font-mono">{cashMoney(holder.amount_cents)}</span></li>)}
                                </ul>
                                <p className="mt-3 text-xs leading-relaxed text-amber-900">The extra cash remains with these recorded holders. It is excluded from this order’s COD and payout eligibility.</p>
                            </section>
                        )}
                    </aside>
                </div>
            </div>
            {target && <CashActionDialog key={`${target.action}-${target.holderId ?? target.sourceReference ?? ''}`} target={target} account={account} base={base} requestToken={requestToken} onClose={() => setTarget(null)} />}
        </CashFrame>
    );
}
