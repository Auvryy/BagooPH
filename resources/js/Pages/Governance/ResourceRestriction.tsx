import { Head, Link } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import RestrictionReview, { RestrictionSubject } from '@/Components/RestrictionReview';

export default function ResourceRestriction({ subject, endpoint, backUrl }: { subject: RestrictionSubject; endpoint: string; backUrl: string }) {
    return <DashboardLayout title="Resource activity review" subtitle="Review local activity, eligible parents and ongoing responsibilities" actions={<Link href={backUrl} className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold">Back to resources</Link>}>
        <Head title="Resource activity — BagooPH" />
        <RestrictionReview key={subject.source_token} subject={subject} endpoint={endpoint} />
    </DashboardLayout>;
}
