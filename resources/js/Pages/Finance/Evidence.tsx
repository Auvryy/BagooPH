import { Head, Link } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { cashButton, cashSurface } from '@/Components/CodCashUI';
import type { OversightMode, OversightRecord } from '@/types/finance';
import { financeDate, financeMoney, financeState } from '@/utils/finance';

export default function Evidence({ record }: { record: OversightRecord; mode: OversightMode }) {
    const proceeds = record.proceeds;
    return <DashboardLayout title={record.order_number} subtitle="Original financial records and linked corrections.">
        <Head title={`Financial evidence · ${record.order_number}`} />
        <div className="space-y-5">
            <Link href="/financial-oversight" className={cashButton}>Financial overview</Link>
            <section className={`${cashSurface} p-5`}><h2 className="font-semibold">Original reporting date</h2><p className="mt-2 text-sm text-slate-600">{record.date_basis} · {financeDate(record.recorded_at)} · Asia/Manila</p>
                {record.unavailable && <p className="mt-3 text-sm text-amber-800">Some original sources require review before their amounts can be verified.</p>}
            </section>
            {proceeds && <section className={`${cashSurface} p-5`}>
                <div className="flex flex-wrap items-center justify-between gap-2"><h2 className="font-semibold">Seller product proceeds</h2><span className="text-sm font-semibold">{financeState(proceeds.status)}</span></div>
                <p className="mt-2 text-sm text-slate-600">{proceeds.seller_name ?? 'Original recipient requires review'} · {proceeds.shop_name ?? 'Shop unavailable'}</p>
                <dl className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">{[['Product sales', proceeds.product_cents], ['Seller share (90%)', proceeds.seller_cents], ['Platform share (10%)', proceeds.commission_cents], ['Shipping charge', proceeds.shipping_cents], ['Order discount', proceeds.discount_cents]].map(([label, amount]) => <div key={String(label)}><dt className="text-sm text-slate-600">{label}</dt><dd className="mt-1 text-lg font-bold tabular-nums">{financeMoney(amount as number)}</dd></div>)}</dl>
                <div className="mt-5 space-y-2 break-words text-sm text-slate-600"><p>Settlement: {proceeds.reference ?? 'Awaiting verified release'}</p><p>Buyer confirmation: {proceeds.buyer_reference ?? 'Not recorded'}</p><p>Cash: {proceeds.cash_reference ?? 'Not recorded'}</p><p>Reconciliation: {proceeds.reconciliation_reference ?? 'Not recorded'}</p></div>
                {proceeds.blockers.length > 0 && <ul className="mt-4 list-inside list-disc space-y-2 text-sm text-amber-800">{proceeds.blockers.map(reason => <li key={reason}>{reason}</li>)}</ul>}
            </section>}
            {record.cash && <section className={`${cashSurface} p-5`}><h2 className="font-semibold">Original COD collection</h2><div className="mt-3 space-y-2 break-words text-sm text-slate-600"><p>{record.cash.reference}</p><p>Latest recorded event: {record.cash.journal_reference}</p><p>Original company #{record.cash.company_id} · destination hub #{record.cash.hub_id}</p><p>Saved amount due: {financeMoney(record.cash.expected_cents)}</p></div>
                <dl className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">{Object.entries(record.cash.amounts).map(([stage, amount]) => <div key={stage}><dt className="text-sm text-slate-600">{financeState(stage)}</dt><dd className="mt-1 text-lg font-bold tabular-nums">{financeMoney(amount)}</dd></div>)}</dl>
            </section>}
            {record.cash?.history && <section className="space-y-4" aria-label="Cash evidence history"><h2 className="text-lg font-bold">Cash history</h2>{record.cash.history.map(event => <article id={event.reference} key={event.reference} className={`${cashSurface} scroll-mt-5 p-5`}>
                <div className="flex flex-wrap justify-between gap-2"><h3 className="font-semibold">{event.sequence}. {financeState(event.event_type)}</h3><p className="text-xs text-slate-600">{financeDate(event.created_at)}</p></div>
                <p className="mt-2 break-words text-sm text-slate-600">{event.reference}</p><p className="mt-2 text-sm text-slate-600">Recorded by {financeState(event.actor_role)} #{event.actor_id}</p>
                {event.from_user_id && <p className="mt-2 text-sm text-slate-600">From {event.from_stage} #{event.from_user_id} to {event.to_stage} #{event.to_user_id}</p>}
                {event.source_reference && <p className="mt-2 break-words text-sm">Original event: <a href={`#${event.source_reference}`} className="font-semibold text-[#C20836] underline">{event.source_reference}</a></p>}
                {event.evidence_reference && <p className="mt-2 break-words text-sm text-slate-600">Evidence: {event.evidence_reference}</p>}
                {event.reason && <p className="mt-2 text-sm leading-6 text-slate-600">{event.reason}</p>}
                <div className="mt-4 grid gap-3 sm:grid-cols-2">{[['Before', event.before], ['After', event.after]].map(([label, amounts]) => <div key={String(label)} className="rounded-[8px] border border-slate-300 bg-slate-50 p-3"><p className="text-sm font-semibold">{String(label)}</p><dl className="mt-2 space-y-1 text-sm">{Object.entries(amounts).map(([stage, amount]) => <div key={stage} className="flex flex-wrap justify-between gap-2"><dt className="text-slate-600">{financeState(stage)}</dt><dd className="font-semibold tabular-nums">{financeMoney(amount)}</dd></div>)}</dl></div>)}</div>
            </article>)}</section>}
            {proceeds?.history && <section className="space-y-4" aria-label="Seller payment evidence history"><h2 className="text-lg font-bold">Release and payment history</h2>{proceeds.history.map(event => <article id={event.reference} key={event.reference} className={`${cashSurface} scroll-mt-5 p-5`}>
                <div className="flex flex-wrap justify-between gap-2"><h3 className="font-semibold">{financeState(event.event_type)}</h3><p className="text-xs text-slate-600">{financeDate(event.created_at)}</p></div>
                <p className="mt-2 break-words text-sm text-slate-600">{event.reference}</p><p className="mt-2 text-sm text-slate-600">{event.actor_name} · {financeState(event.actor_role)}</p>
                <p className="mt-3 font-bold tabular-nums">{financeMoney(event.amount_cents)}</p><p className="mt-2 text-sm leading-6 text-slate-600">{event.reason}</p>
                {event.source_reference && <p className="mt-2 break-words text-sm">Original payment: <a className="font-semibold text-[#C20836] underline" href={`#${event.source_reference}`}>{event.source_reference}</a></p>}
                {event.context.previous_reference && <p className="mt-2 break-words text-sm text-slate-600">Previous receipt reference: {event.context.previous_reference}</p>}
                {event.payment_reference && <p className="mt-2 break-words text-sm text-slate-600">Receipt reference: {event.payment_reference}</p>}
                {event.proof_url && <a href={event.proof_url} className={`${cashButton} mt-4`}>Download private receipt</a>}
            </article>)}</section>}
        </div>
    </DashboardLayout>;
}
