import { Head } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';

export default function Shops() {
    return <DashboardLayout title="Shop unavailable" subtitle="Your registered shop needs attention.">
        <Head title="Shop unavailable" />
        <section className="max-w-3xl rounded-lg border border-slate-300 bg-white p-6 text-sm text-slate-600">
            <p>Your registered shop is missing. Contact an administrator to review your account.</p>
        </section>
    </DashboardLayout>;
}
