import React from 'react';
import type { UnavailableFinance } from '@/types/admin';

export default function AdminFinanceAvailability({ finance }: { finance: UnavailableFinance[] }) {
    return <section className="rounded-xl border border-slate-300 bg-white p-6">
        <h2 className="font-semibold text-slate-900">Finance availability</h2>
        <p className="mt-2 text-sm text-slate-600">Product commission is 10%, with 90% for the seller. Shipping and handling are separate. These rules do not establish amounts collected, reconciled or paid.</p>
        <div className="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">{finance.map((item) => <div key={item.key} className="rounded-lg border border-slate-300 p-4"><h3 className="text-sm font-semibold text-slate-800">{item.label}</h3><p className="mt-2 text-lg font-semibold text-slate-500">Unavailable</p><p className="mt-2 text-xs leading-5 text-slate-500">{item.reason}</p></div>)}</div>
    </section>;
}
