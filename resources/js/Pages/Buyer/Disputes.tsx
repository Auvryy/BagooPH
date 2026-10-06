import { Head, Link } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import BuyerLayout from '@/Layouts/BuyerLayout';

export default function BuyerDisputes() {
    return (
        <BuyerLayout>
            <Head title="Returns and disputes — BagooPH" />
            <section className="rounded-2xl border border-slate-300 bg-white p-6 sm:p-8 shadow-xs">
                <div className="flex items-center gap-3">
                    <ShieldAlert className="h-6 w-6 shrink-0 text-[#E00D42]" aria-hidden="true" />
                    <h1 className="text-xl font-bold text-slate-900">Returns and disputes</h1>
                </div>
                <p className="mt-5 font-semibold text-slate-800">Currently unavailable</p>
                <p className="mt-2 max-w-xl text-sm leading-6 text-slate-600">
                    Returns, refunds and exchanges are not available yet. You can still view your orders and their recorded delivery updates.
                </p>
                <Link href={route('buyer.orders.index')} className="mt-6 inline-flex rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-[#E00D42] hover:bg-rose-50 focus-visible:outline-2 focus-visible:outline-[#E00D42]">
                    View my orders
                </Link>
            </section>
        </BuyerLayout>
    );
}
