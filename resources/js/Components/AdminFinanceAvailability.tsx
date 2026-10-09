import React from 'react';
import { Link } from '@inertiajs/react';
import type { UnavailableFinance } from '@/types/admin';
import { financeMoney } from '@/utils/finance';

export default function AdminFinanceAvailability({ finance }: { finance: UnavailableFinance[] }) {
    return <section className="rounded-xl border border-slate-300 bg-white p-6">
        <h2 className="font-semibold text-slate-900">Recorded finances</h2>
        <p className="mt-2 text-sm text-slate-600">Recorded cash and seller payments have separate sources. Product commission is 10%, with 90% for the seller; shipping and handling stay separate.</p>
        <div className="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">{finance.map((item) => <div key={item.key} className="rounded-lg border border-slate-300 p-4"><h3 className="text-sm font-semibold text-slate-800">{item.label}</h3><p className="mt-2 text-lg font-semibold tabular-nums text-slate-900">{financeMoney(item.amount_cents)}</p><p className="mt-2 text-xs leading-5 text-slate-500">{item.reason}</p>{item.url && <Link href={item.url} className="mt-3 inline-flex min-h-11 items-center text-sm font-semibold text-[#C20836] underline underline-offset-4">Review source records</Link>}</div>)}</div>
    </section>;
}
