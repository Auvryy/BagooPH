import { Head, Link } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Advertisement } from '@/Components/BuyerAdvertisements';
import AdvertisementPreviewFrame from '@/Components/AdvertisementPreviewFrame';
import { Draft, dateLabel, slotLabel } from '@/types/advertisements';

interface Props { advertisementId: number; revision: Draft; banners: Advertisement[]; problem: string | null; baseUrl: string; previewSlot?: string }
export default function AdvertisementPreview({ advertisementId, revision, banners, problem, baseUrl, previewSlot }: Props) {
    return <DashboardLayout title="Advertisement preview" subtitle="Private preview of a saved revision. This does not publish or measure buyer activity."
        actions={<Link href={`${baseUrl}/${advertisementId}`} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-bold">Back to advertisement</Link>}>
        <Head title="Private advertisement preview"><meta name="robots" content="noindex,nofollow" /></Head>
        <div className="space-y-5">
            <h2 className="font-bold">{revision.title} · Revision {revision.revision_number}</h2>
            {problem && <p role="status" className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm">Before publication: {problem}</p>}
            <AdvertisementPreviewFrame banners={banners} selectedId={-revision.id} />
            <section className="space-y-2 rounded-lg border border-slate-300 bg-white p-4 text-sm"><h2 className="font-bold">Destination and requested period</h2><p>{revision.target.label ?? 'No destination selected'} · Preview: {slotLabel(previewSlot ?? revision.slot)}</p><p>{dateLabel(revision.starts_at ? `${revision.starts_at}+08:00` : null)} – {dateLabel(revision.ends_at ? `${revision.ends_at}+08:00` : null)} (Manila)</p>{revision.summary && <p>{revision.summary}</p>}{revision.offer && <p>Linked published offer: {revision.offer.name} · {revision.offer.code}</p>}<p className="text-slate-500">Only live eligible advertisements appear around this saved revision. Confirm that all visible claims match the linked offer; this preview cannot verify text inside artwork.</p></section>
        </div>
    </DashboardLayout>;
}
