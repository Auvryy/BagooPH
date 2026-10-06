import { Head } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Shop } from '@/types';

export default function SellerDisputes({ shop }: { shop: Shop }) {
    return (
        <DashboardLayout title="Returns and disputes" subtitle={shop.name}>
            <Head title="Returns and disputes — BagooPH Seller" />
            <section className="rounded-2xl border border-slate-300 bg-white p-6 sm:p-8 shadow-xs">
                <div className="flex items-center gap-3">
                    <ShieldAlert className="h-6 w-6 shrink-0 text-[#E00D42]" aria-hidden="true" />
                    <h2 className="text-lg font-bold text-slate-900">Currently unavailable</h2>
                </div>
                <p className="mt-4 max-w-xl text-sm leading-6 text-slate-600">
                    Returns, refunds and exchanges are not available yet. No claim responses or refund approvals can be submitted here.
                </p>
            </section>
        </DashboardLayout>
    );
}
