import { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import BuyerAdvertisements, { Advertisement } from '@/Components/BuyerAdvertisements';
import { Draft, dateLabel, slotLabel } from '@/types/advertisements';
import '../../../../css/advertisement-preview.css';

interface Props { advertisementId: number; revision: Draft; banners: Advertisement[]; problem: string | null; baseUrl: string }
export default function AdvertisementPreview({ advertisementId, revision, banners, problem, baseUrl }: Props) {
    const [mobile, setMobile] = useState(false);
    return <DashboardLayout title="Advertisement preview" subtitle="Private preview of a saved revision. This does not publish or measure buyer activity."
        actions={<Link href={`${baseUrl}/${advertisementId}`} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-bold">Back to advertisement</Link>}>
        <Head title="Private advertisement preview"><meta name="robots" content="noindex,nofollow" /></Head>
        <div className="space-y-5">
            <div className="flex flex-wrap items-center gap-3"><h2 className="mr-auto font-bold">{revision.title} · Revision {revision.revision_number}</h2><button onClick={() => setMobile(false)} aria-pressed={!mobile} className="rounded-lg border border-slate-300 px-4 py-2 text-sm">Desktop</button><button onClick={() => setMobile(true)} aria-pressed={mobile} className="rounded-lg border border-slate-300 px-4 py-2 text-sm">Mobile</button></div>
            {problem && <p role="status" className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm">Before publication: {problem}</p>}
            <div className="overflow-x-auto rounded-lg border border-slate-300 bg-slate-50 p-4">
                <div style={{ width: mobile ? 390 : 1140, minHeight: mobile ? 420 : 260, containerType: 'inline-size' }} className="advertisement-preview mx-auto bg-[#F7F9FC] p-3">
                    <BuyerAdvertisements banners={banners} interactive={false} initialSelectedId={-revision.id} />
                </div>
            </div>
            <section className="space-y-2 rounded-lg border border-slate-300 bg-white p-4 text-sm"><h2 className="font-bold">Destination and period</h2><p>{revision.target.label ?? 'No destination selected'} · {slotLabel(revision.slot)}</p><p>{dateLabel(revision.starts_at ? `${revision.starts_at}+08:00` : null)} – {dateLabel(revision.ends_at ? `${revision.ends_at}+08:00` : null)} (Manila)</p>{revision.summary && <p>{revision.summary}</p>}{revision.offer && <p>Linked published offer: {revision.offer.name} · {revision.offer.code}</p>}<p className="text-slate-500">Only live eligible advertisements appear around this saved revision. Confirm that all visible claims match the linked offer; this preview cannot verify text inside artwork.</p></section>
        </div>
    </DashboardLayout>;
}
