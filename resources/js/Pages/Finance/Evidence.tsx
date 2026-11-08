import { Head, Link } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { cashButton } from '@/Components/CodCashUI';
import type { OversightMode, OversightRecord } from '@/types/finance';
import { financeDate, financeMoney, financeState } from '@/utils/finance';

export default function Evidence({ record }: { record: OversightRecord; mode: OversightMode }) {
    const proceeds = record.proceeds;
    return (
        <DashboardLayout title={record.order_number} subtitle="Original financial records and linked corrections.">
            <Head title={`Financial evidence · ${record.order_number}`} />
            <div className="space-y-5">
                <Link href="/financial-oversight" className={cashButton}>Financial overview</Link>

                <section className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-5 shadow-xs">
                    <h2 className="text-sm font-semibold text-[#121316]">Original reporting date</h2>
                    <p className="mt-1.5 text-xs text-zinc-500">{record.date_basis} · {financeDate(record.recorded_at)} · Asia/Manila</p>
                    {record.unavailable && <p className="mt-3 text-xs text-amber-800">Some original sources require review before their amounts can be verified.</p>}
                </section>

                {proceeds && (
                    <section className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-5 shadow-xs">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="text-sm font-semibold text-[#121316]">Seller product proceeds</h2>
                            <span className="rounded-md border border-zinc-200/90 bg-zinc-50 px-2.5 py-0.5 text-xs font-semibold text-zinc-700">{financeState(proceeds.status)}</span>
                        </div>
                        <p className="mt-1.5 text-xs text-zinc-500">{proceeds.seller_name ?? 'Original recipient requires review'} · {proceeds.shop_name ?? 'Shop unavailable'}</p>
                        <dl className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {[['Product sales', proceeds.product_cents], ['Seller share (90%)', proceeds.seller_cents], ['Platform share (10%)', proceeds.commission_cents], ['Shipping charge', proceeds.shipping_cents], ['Order discount', proceeds.discount_cents]].map(([label, amount]) => (
                                <div key={String(label)} className="rounded-lg border border-zinc-100 bg-zinc-50/40 p-3">
                                    <dt className="text-xs font-semibold text-zinc-500">{label}</dt>
                                    <dd className="mt-1 font-mono text-lg font-bold tabular-nums text-[#121316]">{financeMoney(amount as number)}</dd>
                                </div>
                            ))}
                        </dl>
                        {proceeds.promotion_accounting === 'legacy_recorded' ? (
                            <p className="mt-4 text-xs text-amber-800">This earlier settlement retains its recorded payment. Promotional funding history has not been reconstructed.</p>
                        ) : (
                            proceeds.promotion_accounting && (
                                <dl className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                    {[['Seller promotional cost', proceeds.seller_promotion_cents], ['Bagoo promotional cost', proceeds.platform_promotion_cents], [proceeds.return_seller_cents > 0 ? 'Original funded seller share' : 'Net seller payout', proceeds.net_seller_cents], ['Net platform amount', proceeds.net_commission_cents]].map(([label, amount]) => (
                                        <div key={String(label)} className="rounded-lg border border-zinc-100 bg-zinc-50/40 p-3">
                                            <dt className="text-xs font-semibold text-zinc-500">{label}</dt>
                                            <dd className="mt-1 font-mono text-lg font-bold tabular-nums text-[#121316]">{financeMoney(amount as number)}</dd>
                                        </div>
                                    ))}
                                </dl>
                            )
                        )}
                        <div className="mt-5 space-y-1.5 break-words text-xs text-zinc-500">
                            <p>Settlement: <span className="font-mono text-[#121316]">{proceeds.reference ?? 'Awaiting verified release'}</span></p>
                            <p>Order completion: <span className="font-mono text-[#121316]">{proceeds.buyer_reference ?? 'Not recorded'}</span></p>
                            <p>Cash: <span className="font-mono text-[#121316]">{proceeds.cash_reference ?? 'Not recorded'}</span></p>
                            <p>Reconciliation: <span className="font-mono text-[#121316]">{proceeds.reconciliation_reference ?? 'Not recorded'}</span></p>
                        </div>
                        {proceeds.blockers.length > 0 && (
                            <ul className="mt-4 list-inside list-disc space-y-1.5 text-xs text-amber-800">
                                {proceeds.blockers.map(reason => <li key={reason}>{reason}</li>)}
                            </ul>
                        )}
                    </section>
                )}

                {record.cash && (
                    <section className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-5 shadow-xs">
                        <h2 className="text-sm font-semibold text-[#121316]">Original COD collection</h2>
                        <div className="mt-3 space-y-1.5 break-words text-xs text-zinc-500">
                            <p className="font-mono text-[#121316]">{record.cash.reference}</p>
                            <p>Latest recorded event: <span className="font-mono">{record.cash.journal_reference}</span></p>
                            <p>Original company #{record.cash.company_id} · destination hub #{record.cash.hub_id}</p>
                            <p>Collected: {financeDate(record.cash.collected_at)}</p>
                            <p>Recorded in Bagoo: {financeDate(record.cash.recorded_at)}</p>
                            <p>Saved amount due: <span className="font-mono font-semibold text-[#121316]">{financeMoney(record.cash.expected_cents)}</span></p>
                        </div>
                        <dl className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {Object.entries(record.cash.amounts).map(([stage, amount]) => (
                                <div key={stage} className="rounded-lg border border-zinc-100 bg-zinc-50/40 p-3">
                                    <dt className="text-xs font-semibold text-zinc-500">{financeState(stage)}</dt>
                                    <dd className="mt-1 font-mono text-lg font-bold tabular-nums text-[#121316]">{financeMoney(amount)}</dd>
                                </div>
                            ))}
                        </dl>
                    </section>
                )}

                {proceeds && proceeds.return_seller_cents > 0 && (
                    <section className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-5 shadow-xs">
                        <h2 className="text-sm font-semibold text-[#121316]">Separate buyer-return amounts</h2>
                        <dl className="mt-4 grid gap-3 sm:grid-cols-3">
                            {[['Original seller contributions returned', proceeds.return_seller_cents], ['Actual seller payment recorded', proceeds.paid_seller_cents], ['Remaining unpaid proceeds', ['settled', 'closed'].includes(proceeds.status) ? 0 : proceeds.payable_seller_cents]].map(([label, cents]) => (
                                <div key={String(label)} className="rounded-lg border border-zinc-100 bg-zinc-50/40 p-3">
                                    <dt className="text-xs font-semibold text-zinc-500">{label}</dt>
                                    <dd className="mt-1 font-mono font-bold text-[#121316]">{financeMoney(cents as number)}</dd>
                                </div>
                            ))}
                        </dl>
                        <Link href="/buyer-returns" className={`${cashButton} mt-4`}>Review return and recovery records</Link>
                    </section>
                )}

                {record.cash?.history && (
                    <section className="space-y-3" aria-label="Cash evidence history">
                        <h2 className="text-sm font-bold text-[#121316]">Cash history</h2>
                        {record.cash.history.map(event => (
                            <article id={event.reference} key={event.reference} className="scroll-mt-5 overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-5 shadow-xs">
                                <div className="flex flex-wrap justify-between gap-2">
                                    <h3 className="text-sm font-semibold text-[#121316]">{event.sequence}. {financeState(event.event_type)}</h3>
                                    <p className="text-xs text-zinc-500">{financeDate(event.created_at)}</p>
                                </div>
                                <p className="mt-1.5 break-words font-mono text-xs text-zinc-500">{event.reference}</p>
                                <p className="mt-1.5 text-xs text-zinc-600">Recorded by {financeState(event.actor_role)} #{event.actor_id}</p>
                                {event.from_user_id && <p className="mt-1 text-xs text-zinc-600">From {event.from_stage} #{event.from_user_id} to {event.to_stage} #{event.to_user_id}</p>}
                                {event.source_reference && (
                                    <p className="mt-1.5 break-words text-xs">
                                        Original event: <a href={`#${event.source_reference}`} className="font-mono font-semibold text-[#C20836] underline">{event.source_reference}</a>
                                    </p>
                                )}
                                {event.evidence_reference && <p className="mt-1.5 break-words font-mono text-xs text-zinc-500">Evidence: {event.evidence_reference}</p>}
                                {event.reason && <p className="mt-1.5 text-xs leading-relaxed text-zinc-600">{event.reason}</p>}
                                <div className="mt-4 grid gap-3 sm:grid-cols-2">
                                    {[['Before', event.before], ['After', event.after]].map(([label, amounts]) => (
                                        <div key={String(label)} className="rounded-lg border border-zinc-200/80 bg-zinc-50/70 p-3">
                                            <p className="text-xs font-semibold text-[#121316]">{String(label)}</p>
                                            <dl className="mt-2 space-y-1 text-xs">
                                                {Object.entries(amounts).map(([stage, amount]) => (
                                                    <div key={stage} className="flex flex-wrap justify-between gap-2">
                                                        <dt className="text-zinc-500">{financeState(stage)}</dt>
                                                        <dd className="font-mono font-semibold tabular-nums text-[#121316]">{financeMoney(amount)}</dd>
                                                    </div>
                                                ))}
                                            </dl>
                                        </div>
                                    ))}
                                </div>
                            </article>
                        ))}
                    </section>
                )}

                {proceeds?.history && (
                    <section className="space-y-3" aria-label="Seller payment evidence history">
                        <h2 className="text-sm font-bold text-[#121316]">Release and payment history</h2>
                        {proceeds.history.map(event => (
                            <article id={event.reference} key={event.reference} className="scroll-mt-5 overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-5 shadow-xs">
                                <div className="flex flex-wrap justify-between gap-2">
                                    <h3 className="text-sm font-semibold text-[#121316]">{financeState(event.event_type)}</h3>
                                    <p className="text-xs text-zinc-500">{financeDate(event.created_at)}</p>
                                </div>
                                <p className="mt-1.5 break-words font-mono text-xs text-zinc-500">{event.reference}</p>
                                <p className="mt-1 text-xs text-zinc-600">{event.actor_name} · {financeState(event.actor_role)}</p>
                                <p className="mt-2.5 font-mono text-base font-bold tabular-nums text-[#121316]">{financeMoney(event.amount_cents)}</p>
                                <p className="mt-1.5 text-xs leading-relaxed text-zinc-600">{event.reason}</p>
                                {event.source_reference && (
                                    <p className="mt-1.5 break-words text-xs">
                                        Original payment: <a className="font-mono font-semibold text-[#C20836] underline" href={`#${event.source_reference}`}>{event.source_reference}</a>
                                    </p>
                                )}
                                {event.context.previous_reference && <p className="mt-1.5 break-words font-mono text-xs text-zinc-500">Previous receipt reference: {event.context.previous_reference}</p>}
                                {event.payment_reference && <p className="mt-1.5 break-words font-mono text-xs text-zinc-500">Receipt reference: {event.payment_reference}</p>}
                                {event.proof_url && <a href={event.proof_url} className={`${cashButton} mt-3`}>Download private receipt</a>}
                            </article>
                        ))}
                    </section>
                )}
            </div>
        </DashboardLayout>
    );
}
