import { FormEvent, useCallback, useEffect, useRef, useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { PageProps } from '@/types';
import { ArrowLeft, FileImage, RefreshCw, PackageCheck } from 'lucide-react';
import { HubPageHeader } from '@/Components/Logistics/Workspace';
import ReturnLayout from '@/Components/Returns/ReturnLayout';
import ReturnStatus, { returnDate, returnLabels } from '@/Components/Returns/ReturnStatus';
import useRequestError from '@/hooks/useCourierRequestError';
import ReturnCustodyPanel, { ReturnCustody } from '@/Components/Returns/ReturnCustodyPanel';
import ReturnMoneyPanel, { ReturnMoney } from '@/Components/Returns/ReturnMoneyPanel';

interface ReturnCase {
    id: number; reference: string; status: string; version: number; reason: string; description: string;
    order: { id: number; order_number: string }; actions: string[]; request_until: string;
    response_until: string; appeal_until: string | null; dropoff_until: string | null;
    items: Array<{ id: number; quantity: number; purchase: { name: string; color: string | null; size: string | null; unit_price: string } }>;
    evidence: Array<{ id: number; url: string; mime: string; bytes: number; purpose?: string }>;
    history: Array<{ reference: string; event_type: string; actor_role: string; reason: string; target_status: string; created_at: string }>;
    custody?: ReturnCustody | null;
    money?: ReturnMoney | null;
}
const actionNames: Record<string, string> = { approve: 'Approve return', reject: 'Reject return', escalate: 'Ask admin to review', withdraw: 'Withdraw request', 'close-period': 'Close expired period' };
const reasons: Record<string, string> = { damaged: 'Item damaged', wrong_item: 'Wrong item', missing_parts: 'Missing parts', not_as_described: 'Not as described' };

export default function ShowReturn({ returnCase: item }: { returnCase: ReturnCase }) {
    const { auth } = usePage<PageProps>().props;
    const form = useForm({ action: '', reason: '', expected_version: item.version, request_token: crypto.randomUUID() });
    const reasonLimit = auth.user?.role === 'seller' && form.data.action === 'reject' ? 300 : 1000;
    const pending = useRef(false);
    const [failure, setFailure] = useState('');
    const [refreshing, setRefreshing] = useState(false);
    useRequestError(pending, useCallback(message => setFailure(message), []));
    useEffect(() => { form.setData({ action: '', reason: '', expected_version: item.version, request_token: crypto.randomUUID() }); form.clearErrors(); }, [item.id, item.version]);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (pending.current || form.processing || !item.actions.includes(form.data.action)) return;
        pending.current = true; setFailure('');
        form.post(`/buyer-returns/${item.id}/actions`, { preserveScroll: true, onFinish: () => { pending.current = false; } });
    };
    const refresh = () => {
        if (pending.current) return;
        pending.current = true; setFailure(''); setRefreshing(true);
        router.reload({ only: ['returnCase', 'auth', 'flash'], onError: () => setFailure('The return could not refresh. Try again.'), onFinish: () => { pending.current = false; setRefreshing(false); } });
    };

    return (
        <ReturnLayout>
            <Head title={`${item.reference} — BagooPH returns`} />
            <div className={`${auth.user?.role === 'logistics' ? 'max-w-5xl' : 'mx-auto max-w-5xl py-4'} space-y-6`}>
                <Link href="/buyer-returns" className="inline-flex items-center gap-2 text-xs font-bold text-zinc-600 hover:text-[#121316]">
                    <ArrowLeft className="h-4 w-4" />
                    All returns
                </Link>

                <header className="flex flex-wrap items-start justify-between gap-4 border-b border-zinc-200 pb-5">
                    <div className="min-w-0">
                        {auth.user?.role === 'logistics' ? (
                            <HubPageHeader title="Return parcel" icon={PackageCheck} description={`Purchase ${item.order.order_number} · ${item.reference}`} />
                        ) : (
                            <>
                                <h1 className="inline-block break-all rounded-md border border-rose-200 bg-rose-50 px-2.5 py-1 tabular-nums text-sm font-bold text-[#E00D42]">
                                    {item.reference}
                                </h1>
                                <p className="mt-2 text-sm font-semibold text-[#121316]">Purchase {item.order.order_number}</p>
                            </>
                        )}
                        <div className="mt-3">
                            <ReturnStatus status={item.status} />
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={refresh}
                        disabled={form.processing || refreshing}
                        className="inline-flex items-center gap-2 rounded-lg border border-zinc-200/90 bg-white px-3.5 py-2 text-xs font-semibold text-zinc-700 shadow-2xs hover:bg-zinc-50 disabled:opacity-50"
                    >
                        <RefreshCw className="h-4 w-4" />
                        {refreshing ? 'Refreshing…' : 'Refresh return'}
                    </button>
                </header>

                {failure && (
                    <p role="alert" className="rounded-xl border border-rose-300 bg-rose-50 p-4 text-sm font-medium text-rose-800 shadow-xs">
                        {failure}
                    </p>
                )}

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
                    <div className="space-y-6">
                        {item.custody && <ReturnCustodyPanel custody={item.custody} caseId={item.id} version={item.version} />}
                        {item.money && <ReturnMoneyPanel value={item.money} caseId={item.id} version={item.version} />}

                        {/* Purchased Items */}
                        <section className="rounded-xl border border-zinc-200/90 bg-white shadow-xs overflow-hidden">
                            <header className="border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5">
                                <h2 className="text-sm font-bold text-[#121316]">Purchased items</h2>
                            </header>
                            <div className="p-5 sm:p-6">
                                <ul className="divide-y divide-zinc-100">
                                    {item.items.map(line => (
                                        <li key={line.id} className="py-3 first:pt-0">
                                            <p className="break-words text-sm font-bold text-[#121316]">{line.purchase.name}</p>
                                            <p className="mt-1 text-xs text-zinc-500">
                                                {[line.purchase.color, line.purchase.size].filter(Boolean).join(' · ')} · Returning {line.quantity}
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                                <div className="mt-5 border-t border-zinc-100 pt-4">
                                    <h3 className="text-xs font-bold text-[#121316]">{reasons[item.reason] ?? 'Item problem'}</h3>
                                    <p className="mt-2 whitespace-pre-wrap break-words text-sm leading-6 text-zinc-700">{item.description}</p>
                                </div>
                            </div>
                        </section>

                        {/* Private Photos */}
                        <section className="rounded-xl border border-zinc-200/90 bg-white shadow-xs overflow-hidden">
                            <header className="border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5">
                                <h2 className="text-sm font-bold text-[#121316]">Private photos</h2>
                                <p className="mt-0.5 text-xs text-zinc-500">Available to the involved owners and authorized reviewers.</p>
                            </header>
                            <div className="p-5 sm:p-6">
                                <ul className="flex flex-wrap gap-3">
                                    {item.evidence.map((photo, index) => (
                                        <li key={photo.id}>
                                            <a
                                                href={photo.url}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="flex items-center gap-2 rounded-lg border border-zinc-200/90 bg-zinc-50/40 px-3.5 py-2.5 text-xs font-bold text-[#E00D42] hover:bg-rose-50/70 hover:border-rose-200 transition-colors"
                                            >
                                                <FileImage className="h-4 w-4" />
                                                {photo.purpose === 'intake' ? 'Hub intake' : photo.purpose === 'seller-receive' ? 'Seller receipt' : 'Request photo'} {index + 1}
                                                <span className="text-xs font-normal text-zinc-500">{(photo.bytes / 1048576).toFixed(2)} MB</span>
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        </section>

                        {/* Decisions and Updates */}
                        <section className="rounded-xl border border-zinc-200/90 bg-white shadow-xs overflow-hidden">
                            <header className="border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5">
                                <h2 className="text-sm font-bold text-[#121316]">Decisions and updates</h2>
                            </header>
                            <div className="p-5 sm:p-6">
                                <ol className="ml-2 border-l border-zinc-200 pl-6">
                                    {item.history.map(event => (
                                        <li key={event.reference} className="relative pb-6 last:pb-0">
                                            <span className="absolute -left-[29px] top-1.5 h-2.5 w-2.5 rounded-full border-2 border-white bg-[#E00D42] ring-1 ring-zinc-300" aria-hidden="true" />
                                            <div className="flex flex-wrap justify-between gap-2">
                                                <h3 className="text-sm font-bold text-[#121316]">{returnLabels[event.target_status] ?? 'Return update'}</h3>
                                                <p className="text-xs text-zinc-500">{returnDate(event.created_at)}</p>
                                            </div>
                                            <p className="mt-1 text-xs capitalize text-zinc-500">{event.actor_role}</p>
                                            <p className="mt-2 whitespace-pre-wrap break-words text-sm leading-6 text-zinc-700">{event.reason}</p>
                                        </li>
                                    ))}
                                </ol>
                            </div>
                        </section>
                    </div>

                    <aside className="space-y-5">
                        {/* What Happens Next */}
                        <section className="rounded-xl border border-zinc-200/90 bg-white shadow-xs overflow-hidden">
                            <header className="border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5">
                                <h2 className="text-sm font-bold text-[#121316]">What happens next?</h2>
                            </header>
                            <div className="p-5">
                                <p className="text-xs sm:text-sm leading-6 text-zinc-600">
                                    {item.status === 'requested'
                                        ? 'The seller reviews your original items and photos. If the response period passes, you can ask an admin to review.'
                                        : item.status === 'rejected'
                                          ? 'Read the seller’s exact reason in the updates. You can ask an admin to review before the appeal deadline.'
                                          : item.status === 'escalated'
                                            ? 'An admin reviews the request and the recorded seller feedback. No repayment is implied while the case is being reviewed.'
                                            : item.status === 'approved'
                                              ? 'Return transport is approved. Physical hub intake and each handover must be recorded before repayment can be reviewed.'
                                              : ['withdrawn', 'expired', 'denied'].includes(item.status)
                                                ? 'This request is closed without a repayment. Its original evidence and decisions remain available here.'
                                                : item.status === 'repaid'
                                                  ? 'You confirmed receiving the recorded repayment. The original purchase, delivery and payment evidence remain available.'
                                                  : item.status === 'closed_no_repayment'
                                                    ? 'The returned merchandise was fully covered by its original product discounts. No merchandise repayment was due and no outgoing transfer was recorded.'
                                                    : item.status === 'payment_recorded'
                                                      ? 'A manual repayment was recorded. Check that you received it, then confirm receipt or report a missing payment below.'
                                                      : item.status === 'repayment_disputed'
                                                        ? 'Your non-receipt report is awaiting payment review. The original payment stays retained; no second repayment is authorized.'
                                                        : item.status === 'repayment_ready'
                                                          ? 'The original repayment amount is approved. Actual backing and a recorded transfer are required before you can confirm receipt.'
                                                          : 'Follow the actual parcel and payment records below. Physical receipt and repayment are separate steps.'}
                                </p>
                                <dl className="mt-4 space-y-3 border-t border-zinc-100 pt-4 text-xs">
                                    <div>
                                        <dt className="font-bold text-[#121316]">Seller response by</dt>
                                        <dd className="mt-1 text-zinc-600">{returnDate(item.response_until)}</dd>
                                    </div>
                                    {item.appeal_until && (
                                        <div>
                                            <dt className="font-bold text-[#121316]">Request admin review by</dt>
                                            <dd className="mt-1 text-zinc-600">{returnDate(item.appeal_until)}</dd>
                                        </div>
                                    )}
                                    {item.dropoff_until && (
                                        <div>
                                            <dt className="font-bold text-[#121316]">Physical drop-off by</dt>
                                            <dd className="mt-1 text-zinc-600">{returnDate(item.dropoff_until)}</dd>
                                        </div>
                                    )}
                                </dl>
                            </div>
                        </section>

                        {/* Update This Return Form */}
                        {item.actions.length > 0 && (
                            <form
                                onSubmit={submit}
                                className="rounded-xl border border-zinc-200/90 bg-white shadow-xs overflow-hidden"
                                aria-busy={form.processing || undefined}
                            >
                                <header className="border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5">
                                    <h2 className="text-sm font-bold text-[#121316]">Update this return</h2>
                                </header>
                                <div className="p-5 space-y-4">
                                    <label className="block text-xs font-bold text-[#121316]">
                                        Action
                                        <select
                                            required
                                            disabled={form.processing || refreshing}
                                            value={form.data.action}
                                            onChange={event => form.setData('action', event.target.value)}
                                            className="mt-1.5 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-[#121316] focus:border-[#E00D42] focus:ring-[#E00D42]"
                                        >
                                            <option value="">Choose an action</option>
                                            {item.actions.map(action => (
                                                <option key={action} value={action}>{actionNames[action] ?? action}</option>
                                            ))}
                                        </select>
                                    </label>
                                    <label className="block text-xs font-bold text-[#121316]">
                                        Reason
                                        <textarea
                                            required
                                            minLength={10}
                                            maxLength={reasonLimit}
                                            rows={4}
                                            disabled={form.processing || refreshing}
                                            value={form.data.reason}
                                            onChange={event => form.setData('reason', event.target.value)}
                                            className="mt-1.5 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-[#121316] focus:border-[#E00D42] focus:ring-[#E00D42]"
                                        />
                                        <span className="mt-1 block text-xs font-normal text-zinc-500">
                                            10–{reasonLimit.toLocaleString()} characters. Explain the actual decision.
                                        </span>
                                    </label>
                                    {Object.entries(form.errors).map(([key, message]) => (
                                        <p key={key} role="alert" className="text-sm text-rose-700">{String(message)}</p>
                                    ))}
                                    <button
                                        type="submit"
                                        disabled={form.processing || refreshing || !form.data.action}
                                        className="w-full rounded-lg bg-[#E00D42] px-4 py-2.5 text-xs font-bold text-white shadow-xs hover:bg-[#C20836] disabled:opacity-50 transition"
                                    >
                                        {form.processing ? 'Saving…' : actionNames[form.data.action] ?? 'Save update'}
                                    </button>
                                </div>
                            </form>
                        )}
                    </aside>
                </div>
            </div>
        </ReturnLayout>
    );
}
