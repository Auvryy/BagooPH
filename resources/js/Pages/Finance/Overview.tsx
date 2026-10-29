import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';
import { ArrowRight, Building2, Landmark, Receipt, Wallet } from 'lucide-react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { cashButton, cashInput, cashSurface } from '@/Components/CodCashUI';
import type { PageProps } from '@/types';
import type { OversightProps } from '@/types/finance';
import { financeDate, financeMoney, financeState } from '@/utils/finance';

export default function Overview({ records, totals, filters, mode, error, stateOptions, companies, recipients }: OversightProps) {
    const [form, setForm] = useState(filters);
    const { errors } = usePage<PageProps>().props;
    useEffect(() => setForm(filters), [filters]);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get('/financial-oversight', { ...form }, { preserveState: true });
    };
    const update = (key: keyof typeof form, value: string) => setForm(current => ({ ...current, [key]: value }));
    const title = mode === 'seller' ? 'Finances' : 'Financial oversight';
    return <DashboardLayout title={title} subtitle={mode === 'company' ? 'Review your company’s recorded cash and receipts.' : mode === 'seller' ? 'Follow your product proceeds from confirmation to recorded payment.' : 'Review recorded cash and seller proceeds with their original evidence.'}>
        <Head title={title} />
        <div className="space-y-5">
            <section className={`${cashSurface} p-5`}>
                {mode === 'company' ? <><h2 className="font-semibold">Follow the original cash receipts</h2><ol tabIndex={0} aria-label="COD receipt stages; scroll to view every stage" className="mt-4 flex items-center gap-3 overflow-x-auto pb-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42]">{[{ name: 'Buyer payment', Icon: Receipt }, { name: 'Rider or counter', Icon: Wallet }, { name: 'Hub receipt', Icon: Building2 }, { name: 'Platform receipt', Icon: Landmark }].map(({ name, Icon }, index) => <li key={name} className="flex shrink-0 items-center gap-3"><span className="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-700"><Icon className="h-4 w-4 text-slate-400" />{name}</span>{index < 3 && <ArrowRight className="h-3.5 w-3.5 text-slate-300" />}</li>)}</ol><p className="mt-4 text-xs leading-6 text-slate-500">Each card follows the same cash at a different stage. Review its original source record. Product proceeds stay 90% seller / 10% Bagoo; shipping stays separate.</p></> : <>
                <h2 className="font-semibold">{mode === 'seller' ? 'Your proceeds and payments' : 'Recorded money and its sources'}</h2>
                <p className="mt-2 text-sm leading-6 text-slate-600">{mode === 'seller'
                    ? 'See proceeds awaiting payment and payments backed by recorded receipts. Payouts shows the payment history for each order. Sales report shows completed sales and estimated shares.'
                    : 'Cash cards follow the same money at different stages. Review each stage separately. Product proceeds use the original 90% seller and 10% platform split, with shipping and discounts shown separately.'}</p>
                <p className="mt-2 text-sm leading-6 text-slate-600">Date filters use the original cash collection date in Philippine time. Orders awaiting collection use their order date. Each card links to the records contributing to that total.</p>
                </>}
            </section>
            {error && <p role="alert" className="rounded-[8px] border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">{error}</p>}
            {Object.values(errors ?? {}).length > 0 && <div role="alert" className="rounded-[8px] border border-red-300 bg-red-50 p-4 text-sm text-red-800">{Object.values(errors).map((message, index) => <p key={index}>{message}</p>)}</div>}
            <form onSubmit={submit} className={`${cashSurface} grid items-end gap-3 p-5 sm:grid-cols-2 xl:grid-cols-3`}>
                <label className="text-sm font-semibold">From date<input type="date" className={cashInput} value={form.from ?? ''} onChange={event => update('from', event.target.value)} /></label>
                <label className="text-sm font-semibold">Through date<input type="date" className={cashInput} value={form.to ?? ''} onChange={event => update('to', event.target.value)} /></label>
                <label className="text-sm font-semibold">Recorded state<select className={cashInput} value={form.state ?? ''} onChange={event => update('state', event.target.value)}><option value="">All states</option>{stateOptions.map(state => <option key={state} value={state}>{financeState(state)}</option>)}</select></label>
                {mode === 'admin' && <><label className="text-sm font-semibold">Company<select className={cashInput} value={form.company ?? ''} onChange={event => update('company', event.target.value)}><option value="">All companies</option>{companies.map(company => <option key={company.id} value={company.id}>{company.name} · #{company.id}</option>)}</select></label>
                    <label className="text-sm font-semibold">Seller recipient<select className={cashInput} value={form.recipient ?? ''} onChange={event => update('recipient', event.target.value)}><option value="">All recipients</option>{recipients.map(recipient => <option key={recipient.id} value={recipient.id}>{recipient.name} · #{recipient.id}</option>)}</select></label></>}
                <div className="flex flex-wrap gap-2"><button className={cashButton}>Apply filters</button><Link href="/financial-oversight" className={cashButton}>Reset</Link></div>
                {mode === 'company' && <p className="text-xs leading-5 text-slate-500 sm:col-span-2 xl:col-span-3">Dates use the recorded collection in Philippine time. Orders awaiting collection use their original order date.</p>}
            </form>
            {filters.metric && <p className="text-sm text-slate-600">Showing sources for {totals.find(total => total.key === filters.metric)?.label.toLowerCase() ?? financeState(filters.metric)}.</p>}
            <section aria-label="Financial totals" className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">{totals.map(total => <article key={total.key} className={`${cashSurface} p-5`}>
                <h2 className="text-sm font-semibold">{total.label}</h2><p className="mt-3 text-2xl font-bold tabular-nums">{financeMoney(total.amount_cents)}</p>
                <p className="mt-2 text-xs leading-5 text-slate-600">{total.definition}</p>
                {total.amount_cents !== null && total.url && <Link href={total.url} className="mt-3 inline-flex min-h-11 items-center text-sm font-semibold text-[#C20836] underline underline-offset-4">Review {total.source_count} source {total.source_count === 1 ? 'record' : 'records'}</Link>}
                {total.amount_cents === null && !['shipping_income', 'rider_paid'].includes(total.key) && <p className="mt-2 text-xs text-amber-800">Some source records require review.</p>}
            </article>)}</section>
            {!error && <section aria-label="Included financial records" className="grid gap-4 lg:grid-cols-2">{records.data.map(record => <article key={record.order_id} className={`${cashSurface} p-5`}>
                <div className="flex flex-wrap items-center justify-between gap-2"><h2 className="font-semibold">{record.order_number}</h2><span className="rounded-[8px] border border-slate-300 bg-slate-50 px-3 py-1 text-sm">{financeState(record.proceeds?.status ?? record.cash_status)}</span></div>
                <p className="mt-2 text-xs text-slate-600">{record.date_basis} · {financeDate(record.recorded_at)}</p>
                {record.proceeds && <p className="mt-3 text-sm text-slate-600">{record.proceeds.shop_name ?? 'Original shop requires review'}</p>}
                <dl className="mt-4 grid grid-cols-2 gap-3 text-sm">{['held', 'reconciled', 'pending', 'settled'].filter(key => key in record.metrics).map(key => <div key={key}><dt className="text-slate-600">{totals.find(total => total.key === key)?.label}</dt><dd className="mt-1 font-semibold tabular-nums">{financeMoney(record.metrics[key])}</dd></div>)}</dl>
                {record.unavailable && <p className="mt-3 text-sm text-amber-800">Some original financial evidence is unverified.</p>}
                <Link href={record.url} className={`${cashButton} mt-4`}>Review original evidence</Link>
            </article>)}</section>}
            {!error && records.total === 0 && <p className={`${cashSurface} p-8 text-center text-slate-600`}>No records match these filters.</p>}
            {!error && <nav aria-label="Financial record pages" className="flex flex-wrap items-center justify-between gap-3"><p className="text-sm text-slate-600">Page {records.current_page} of {records.last_page} · {records.total} records</p><div className="flex gap-2">{records.prev_page_url && <Link className={cashButton} href={records.prev_page_url}>Previous</Link>}{records.next_page_url && <Link className={cashButton} href={records.next_page_url}>Next</Link>}</div></nav>}
        </div>
    </DashboardLayout>;
}
