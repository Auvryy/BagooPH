import { ComponentPropsWithRef, MouseEvent, ReactElement, ReactNode, cloneElement, useEffect, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { AdvertisementMeasurementData, AdvertisementMeasurementSession } from '@/utils/advertisementMeasurement';

export interface Advertisement {
    id: number;
    title: string;
    image: string;
    alt_text: string;
    width: number;
    height: number;
    slot: string;
    placement: 'main' | 'side';
    href: string;
    event_slug?: string;
    measurement?: AdvertisementMeasurementData;
}

function PictureLink({ href, interactive, children, ...props }: { href: string; interactive: boolean; children: ReactNode; className: string; 'aria-label': string; onClick: () => void; onAuxClick: (event: MouseEvent) => void }) {
    return interactive ? <Link href={href} {...props}>{children}</Link> : <div className={props.className} aria-label={props['aria-label']}>{children}</div>;
}

function MeasuredArtwork({ banner, session, interactive, children }: { banner: Advertisement; session: AdvertisementMeasurementSession; interactive: boolean; children: ReactElement<ComponentPropsWithRef<'img'>> }) {
    const ref = useRef<HTMLImageElement>(null);
    useEffect(() => {
        if (interactive && banner.measurement && ref.current) return session.observe(ref.current, banner.measurement);
    }, [interactive, banner.measurement, session]);
    return cloneElement(children, { ref });
}

const arrow = 'flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 text-ink-900 shadow-sm hover:bg-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500';

/**
 * The marketplace's first section: live Bagoo sale events and reviewed seller or admin promotions.
 * Only real, published banners appear; with nothing live the section is not rendered at all.
 */
export default function BuyerAdvertisements({ banners, interactive = true, initialSelectedId }: { banners: Advertisement[]; interactive?: boolean; initialSelectedId?: number }) {
    const measurement = useRef(new AdvertisementMeasurementSession()).current;
    const [failed, setFailed] = useState<string[]>([]);
    const available = banners.filter(banner => banner.image && !failed.includes(banner.image));
    const slides = available.filter(banner => banner.placement === 'main');
    const side = available.filter(banner => banner.placement === 'side').slice(0, 2);
    const [selected, setSelected] = useState(() => Math.max(0, slides.findIndex(banner => banner.id === initialSelectedId)));
    if (slides.length === 0 && side.length === 0) return null;

    const fail = (image: string) => setFailed(images => (images.includes(image) ? images : [...images, image]));
    const picture = (banner: Advertisement, className: string, eager: boolean) => (
        <PictureLink href={banner.href} interactive={interactive} aria-label={banner.title}
            onClick={() => measurement.click(banner.measurement)}
            onAuxClick={event => { if (event.button === 1) measurement.click(banner.measurement); }}
            className={`block h-full w-full focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand-500 ${className}`}>
            <MeasuredArtwork banner={banner} session={measurement} interactive={interactive}>
                <img key={banner.id} src={banner.image} alt={banner.alt_text} width={banner.width} height={banner.height}
                    loading={eager ? 'eager' : 'lazy'} onError={() => fail(banner.image)} className="block aspect-[47/22] h-full w-full object-cover" />
            </MeasuredArtwork>
        </PictureLink>
    );

    if (slides.length === 0) {
        return <section aria-label="Promotions" className="mb-4 max-w-md overflow-hidden rounded-card bg-surface-100 sm:mb-6">{picture(side[0], '', true)}</section>;
    }

    const index = selected % slides.length;
    const current = slides[index];
    const move = (step: number) => setSelected(value => (value + step + slides.length) % slides.length);
    const carousel = (
        <div role="region" aria-roledescription="carousel" aria-label="Featured promotions" className="relative min-w-0 overflow-hidden bg-surface-100">
            {picture(current, '', true)}
            <p aria-live="polite" className="sr-only">{current.title}</p>
            {slides.length > 1 && (
                <>
                    <button type="button" onClick={() => move(-1)} aria-label="Previous advertisement" className={`absolute left-2 top-1/2 ${arrow}`}><ChevronLeft className="h-5 w-5" aria-hidden="true" /></button>
                    <button type="button" onClick={() => move(1)} aria-label="Next advertisement" className={`absolute right-2 top-1/2 ${arrow}`}><ChevronRight className="h-5 w-5" aria-hidden="true" /></button>
                    <div role="group" aria-label="Choose an advertisement" className="absolute bottom-2 left-1/2 flex -translate-x-1/2 gap-1 rounded-full bg-white/90 px-2 py-1">
                        {slides.map((banner, position) => (
                            <button key={banner.id} type="button" onClick={() => setSelected(position)} aria-label={`Show ${position + 1} of ${slides.length}: ${banner.title}`}
                                aria-current={position === index ? 'true' : undefined}
                                className="flex h-6 w-6 items-center justify-center rounded-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500">
                                <span aria-hidden="true" className={`h-2 rounded-full ${position === index ? 'w-4 bg-brand-500' : 'w-2 bg-ink-400'}`} />
                            </button>
                        ))}
                    </div>
                </>
            )}
        </div>
    );

    if (side.length === 0) {
        return <section aria-label="Promotions" className={`mb-4 overflow-hidden rounded-card sm:mb-6 ${slides.length === 1 ? 'max-w-3xl' : ''}`}>{carousel}</section>;
    }

    return (
        <section aria-label="Promotions" className="mb-4 grid gap-2 overflow-hidden rounded-card sm:mb-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            {carousel}
            <div className="hidden min-w-0 gap-2 lg:grid lg:grid-rows-2">
                {side.map(banner => <div key={banner.id} className="min-h-0 overflow-hidden bg-surface-100">{picture(banner, '', true)}</div>)}
            </div>
        </section>
    );
}
