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

export default function BuyerAdvertisements({ banners, interactive = true, initialSelectedId }: { banners: Advertisement[]; interactive?: boolean; initialSelectedId?: number }) {
    const measurement = useRef(new AdvertisementMeasurementSession()).current;
    const [failed, setFailed] = useState<string[]>([]);
    const available = banners.filter((banner) => !failed.includes(banner.image));
    const slides = available.filter((banner) => banner.placement === 'main').slice(0, 3);
    const supportingAds = available.filter((banner) => banner.placement === 'side').slice(0, 2);
    const [selected, setSelected] = useState(() => Math.max(0, slides.findIndex((banner) => banner.id === initialSelectedId)));
    const currentIndex = selected % Math.max(slides.length, 1);
    const current = slides[currentIndex];

    if (!current && !supportingAds.length) return null;
    const failedImage = (image: string) => setFailed((images) => images.includes(image) ? images : [...images, image]);

    const move = (direction: number) => {
        setSelected((index) => (index + direction + slides.length) % slides.length);
    };

    return (
        <section
            aria-label="Bagoo advertisements"
            data-main={Boolean(current)}
            data-side={Boolean(supportingAds.length)}
            className={`buyer-ad-grid grid min-w-0 grid-cols-1 items-start gap-3 ${current && supportingAds.length ? 'lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]' : 'mx-auto w-full max-w-3xl'}`}
        >
            {current && (
            <div role="region" aria-roledescription="carousel" aria-label="Featured advertisements" className="relative min-w-0">
                <PictureLink
                    href={current.href}
                    interactive={interactive}
                    onClick={() => measurement.click(current.measurement)}
                    onAuxClick={(event) => { if (event.button === 1) measurement.click(current.measurement); }}
                    aria-label={current.title}
                    className="block focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-[#E00D42]"
                >
                    <MeasuredArtwork banner={current} session={measurement} interactive={interactive}><img
                        src={current.image}
                        alt={current.alt_text}
                        width={current.width}
                        height={current.height}
                        onError={() => failedImage(current.image)}
                        loading="eager"
                        className="block h-auto w-full"
                    /></MeasuredArtwork>
                </PictureLink>

                <p aria-live="polite" className="sr-only">{current.title}</p>

                {slides.length > 1 && (
                    <>
                        <button
                            type="button"
                            onClick={() => move(-1)}
                            aria-label="Previous advertisement"
                            className="absolute left-2 top-1/2 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-black/50 text-white hover:bg-black/70 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white sm:left-5 sm:h-12 sm:w-12"
                        >
                            <ChevronLeft className="h-6 w-6" aria-hidden="true" />
                        </button>
                        <button
                            type="button"
                            onClick={() => move(1)}
                            aria-label="Next advertisement"
                            className="absolute right-2 top-1/2 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-black/50 text-white hover:bg-black/70 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white sm:right-5 sm:h-12 sm:w-12"
                        >
                            <ChevronRight className="h-6 w-6" aria-hidden="true" />
                        </button>
                        <div role="group" aria-label="Choose an advertisement" className="absolute bottom-1 left-1/2 flex -translate-x-1/2 sm:bottom-3">
                            {slides.map((banner, index) => (
                                <button
                                    key={banner.id}
                                    type="button"
                                    onClick={() => setSelected(index)}
                                    aria-label={`Show advertisement ${index + 1}: ${banner.title}`}
                                    aria-pressed={currentIndex === index}
                                    className="flex h-8 w-8 items-center justify-center rounded-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-white"
                                >
                                    <span aria-hidden="true" className={`h-1.5 rounded-full shadow-sm ${currentIndex === index ? 'w-5 bg-white' : 'w-1.5 bg-white/60'}`} />
                                </button>
                            ))}
                        </div>
                    </>
                )}
            </div>
            )}

            {supportingAds.length > 0 && (
                <div aria-label="More advertisements" data-count={supportingAds.length} className={`buyer-ad-supporting grid min-w-0 grid-cols-2 gap-3 ${current ? 'lg:grid-cols-1' : supportingAds.length === 1 ? 'mx-auto w-full max-w-md grid-cols-1' : ''}`}>
                    {supportingAds.map((banner) => (
                        <PictureLink
                            key={banner.id}
                            href={banner.href}
                            interactive={interactive}
                            onClick={() => measurement.click(banner.measurement)}
                            onAuxClick={(event) => { if (event.button === 1) measurement.click(banner.measurement); }}
                            aria-label={banner.title}
                            className="block min-w-0 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42]"
                        >
                            <MeasuredArtwork banner={banner} session={measurement} interactive={interactive}><img
                                src={banner.image}
                                alt={banner.alt_text}
                                width={banner.width}
                                height={banner.height}
                                onError={() => failedImage(banner.image)}
                                loading="lazy"
                                decoding="async"
                                className="block h-auto w-full"
                            /></MeasuredArtwork>
                        </PictureLink>
                    ))}
                </div>
            )}
        </section>
    );
}
