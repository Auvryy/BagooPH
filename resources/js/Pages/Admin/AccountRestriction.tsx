import { Head, Link } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import RestrictionReview, { RestrictionSubject } from '@/Components/RestrictionReview';

export default function AccountRestriction({ subject, endpoint, backUrl }: { subject: RestrictionSubject; endpoint: string; backUrl: string }) {
    return <DashboardLayout title="Account activity review" subtitle="Keep approval, activity and ongoing responsibilities separate" actions={<Link href={backUrl} className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold">Back to accounts</Link>}>
        <Head title="Account activity — BagooPH" />
        <RestrictionReview key={subject.source_token} subject={subject} endpoint={endpoint} />
    </DashboardLayout>;
}
