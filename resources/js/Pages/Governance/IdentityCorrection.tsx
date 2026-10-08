import { Head, Link } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import IdentityCorrectionPanel, { type IdentityCorrectionProps } from '@/Components/IdentityCorrectionPanel';

export default function IdentityCorrection(props: IdentityCorrectionProps) {
    return <DashboardLayout title="Reviewed identity corrections" subtitle="Current evidence and preserved review history" actions={<Link href={props.decisionBaseUrl ?? '/admin/identity-corrections'} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">Back to correction queue</Link>}>
        <Head title="Identity review — BagooPH" />
        <IdentityCorrectionPanel {...props} adminReview />
    </DashboardLayout>;
}
