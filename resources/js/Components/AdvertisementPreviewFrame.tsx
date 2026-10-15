import { useState } from 'react';
import BuyerAdvertisements, { Advertisement } from '@/Components/BuyerAdvertisements';
import '../../css/advertisement-preview.css';

export default function AdvertisementPreviewFrame({ banners, selectedId }: { banners: Advertisement[]; selectedId?: number }) {
    const [mobile, setMobile] = useState(false);
    return <div className="space-y-3">
        <div role="group" aria-label="Preview width" className="flex justify-end gap-3">
            <button type="button" onClick={() => setMobile(false)} aria-pressed={!mobile} className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm">Desktop</button>
            <button type="button" onClick={() => setMobile(true)} aria-pressed={mobile} className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm">Mobile</button>
        </div>
        <div className="overflow-x-auto rounded-lg border border-slate-300 bg-slate-50 p-4">
            <div style={{ width: mobile ? 390 : 1140, minHeight: mobile ? 420 : 260, containerType: 'inline-size' }} className="advertisement-preview mx-auto bg-[#F7F9FC] p-3">
                <BuyerAdvertisements banners={banners} interactive={false} initialSelectedId={selectedId} />
            </div>
        </div>
    </div>;
}
