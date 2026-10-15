import { Head, Link } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import AdvertisementPreviewFrame from '@/Components/AdvertisementPreviewFrame';
import AdvertisementOfferTerms from '@/Components/AdvertisementOfferTerms';
import { Draft } from '@/types/advertisements';

export default function PromotionPreview({ promotionId, revision, baseUrl }: { promotionId: number; revision: Draft; baseUrl: string }) {
    return <DashboardLayout title="Promotion preview" subtitle="Private preview of your saved artwork. Platform Admin chooses the final homepage position."
        actions={<Link href={`${baseUrl}/${promotionId}`} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-bold">Back to promotion</Link>}>
        <Head title="Private promotion preview"><meta name="robots" content="noindex,nofollow" /></Head>
        <div className="space-y-5"><h2 className="font-bold">{revision.title} · Revision {revision.revision_number}</h2>
            <AdvertisementPreviewFrame selectedId={-revision.id} banners={[{ id: -revision.id, title: revision.title, alt_text: revision.alt_text, image: revision.image, width: revision.width, height: revision.height, placement: 'main', slot: 'main_1', href: '#' }]} />
            <div className="space-y-2 rounded-lg border border-slate-300 bg-white p-4 text-sm"><p className="font-bold">{revision.target.label ?? 'Select a destination'}</p>{revision.summary && <p>{revision.summary}</p>}<p className="text-slate-500">This preview does not submit or publish your promotion.</p></div>
            {revision.offer && <AdvertisementOfferTerms offer={revision.offer} />}
        </div>
    </DashboardLayout>;
}
