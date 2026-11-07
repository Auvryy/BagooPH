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
    let slides = available.filter((banner) => banner.placement === 'main').slice(0, 5);
    let supportingAds = available.filter((banner) => banner.placement === 'side').slice(0, 2);

    // Ensure the main platform event is scrollable/carousel with diverse campaigns
    if (slides.length <= 1) {
        slides = [
            ...(slides.length > 0 ? slides : []),
            {
                id: 991,
                title: 'Bagoo 11.11 Mega Sale — Up to 90% Off & Free Shipping',
                image: '/images/buyer/advertisements/mega-sale.webp',
                alt_text: 'Bagoo 11.11 Mega Sale Promotion',
                width: 1200,
                height: 480,
                slot: 'main_1',
                placement: 'main' as const,
                href: '#flash-deals',
            },
            {
                id: 992,
                title: 'Mega Carnival Grand Celebration — Extra 15% Vouchers',
                image: '/images/buyer/advertisements/carnival.webp',
                alt_text: 'Mega Carnival Grand Celebration',
                width: 1200,
                height: 480,
                slot: 'main_2',
                placement: 'main' as const,
                href: '/vouchers',
            },
            {
                id: 993,
                title: 'Electronics & Lifestyle Frenzy — Verified Gear & Audio',
                image: '/images/buyer/advertisements/frenzy.webp',
                alt_text: 'Electronics Frenzy Event',
                width: 1200,
                height: 480,
                slot: 'main_3',
                placement: 'main' as const,
                href: '#product-feed',
            },
        ].slice(0, 3);
    }

    if (supportingAds.length < 2) {
        const fallbacks: Advertisement[] = [
            {
                id: 994,
                title: '11.11 Partner Promos — Stack & Save Up to ₱2,000 More',
                image: '/images/buyer/advertisements/carnival.webp',
                alt_text: 'Partner Promos Event',
                width: 600,
                height: 230,
                slot: 'side_1',
                placement: 'side' as const,
                href: '/vouchers',
            },
            {
                id: 995,
                title: 'Free Shipping ₱0 Min Spend — Doorstep Delivery Guarantee',
                image: '/images/buyer/advertisements/frenzy.webp',
                alt_text: 'Free Shipping Vouchers',
                width: 600,
                height: 230,
                slot: 'side_2',
                placement: 'side' as const,
                href: '#flash-deals',
            },
        ];
        supportingAds = [...supportingAds, ...fallbacks.filter(fb => !supportingAds.some(sa => sa.image === fb.image))].slice(0, 2);
    }
    const [selected, setSelected] = useState(() => Math.max(0, slides.findIndex((banner) => banner.id === initialSelectedId)));
    const [isHovered, setIsHovered] = useState(false);
    const currentIndex = selected % Math.max(slides.length, 1);
    const current = slides[currentIndex];

    // Automatic slide cycling every 5 seconds (pauses on hover)
    useEffect(() => {
        if (slides.length <= 1 || isHovered) return;
        const interval = setInterval(() => {
            setSelected((prev) => (prev + 1) % slides.length);
        }, 5000);
        return () => clearInterval(interval);
    }, [slides.length, isHovered]);

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
            className={`buyer-ad-grid grid min-w-0 grid-cols-1 items-stretch gap-4 ${current && supportingAds.length ? 'lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]' : 'mx-auto w-full max-w-4xl'}`}
        >
            {current && (
                <div
                    role="region"
                    aria-roledescription="carousel"
                    aria-label="Featured advertisements"
                    className="relative min-w-0 rounded-2xl overflow-hidden border border-slate-200/90 shadow-[0_4px_16px_rgba(15,23,42,0.06)] bg-slate-100 group"
                    onMouseEnter={() => setIsHovered(true)}
                    onMouseLeave={() => setIsHovered(false)}
                >
                    <PictureLink
                        href={current.href}
                        interactive={interactive}
                        onClick={() => measurement.click(current.measurement)}
                        onAuxClick={(event) => { if (event.button === 1) measurement.click(current.measurement); }}
                        aria-label={current.title}
                        className="block focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-[#E00D42]"
                    >
                        <MeasuredArtwork banner={current} session={measurement} interactive={interactive}>
                            <img
                                key={current.id}
                                src={current.image}
                                alt={current.alt_text}
                                width={current.width}
                                height={current.height}
                                onError={() => failedImage(current.image)}
                                loading="eager"
                                className="block h-auto w-full object-cover transition-transform duration-700 ease-out group-hover:scale-[1.02]"
                            />
                        </MeasuredArtwork>
                    </PictureLink>

                    <p aria-live="polite" className="sr-only">{current.title}</p>

                    {slides.length > 1 && (
                        <>
                            <button
                                type="button"
                                onClick={() => move(-1)}
                                aria-label="Previous advertisement"
                                className="absolute left-3 top-1/2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full bg-[#E00D42]/90 text-white hover:bg-[#E00D42] shadow-md transition-all focus-visible:outline focus-visible:outline-2 focus-visible:outline-white opacity-0 group-hover:opacity-100 cursor-pointer"
                            >
                                <ChevronLeft className="h-5 w-5" aria-hidden="true" />
                            </button>
                            <button
                                type="button"
                                onClick={() => move(1)}
                                aria-label="Next advertisement"
                                className="absolute right-3 top-1/2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full bg-[#E00D42]/90 text-white hover:bg-[#E00D42] shadow-md transition-all focus-visible:outline focus-visible:outline-2 focus-visible:outline-white opacity-0 group-hover:opacity-100 cursor-pointer"
                            >
                                <ChevronRight className="h-5 w-5" aria-hidden="true" />
                            </button>
                            <div role="group" aria-label="Choose an advertisement" className="absolute bottom-3 left-1/2 flex -translate-x-1/2 gap-1.5 bg-white/85 border border-slate-200/80 px-2.5 py-1 rounded-full shadow-sm">
                                {slides.map((banner, index) => (
                                    <button
                                        key={banner.id}
                                        type="button"
                                        onClick={() => setSelected(index)}
                                        aria-label={`Show advertisement ${index + 1}: ${banner.title}`}
                                        aria-pressed={currentIndex === index}
                                        className="flex h-3.5 w-3.5 items-center justify-center rounded-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42] cursor-pointer"
                                    >
                                        <span
                                            aria-hidden="true"
                                            className={`h-1.5 rounded-full transition-all duration-300 ${currentIndex === index ? 'w-5 bg-[#E00D42]' : 'w-1.5 bg-slate-300'}`}
                                        />
                                    </button>
                                ))}
                            </div>
                        </>
                    )}
                </div>
            )}

            {supportingAds.length > 0 && (
                <div aria-label="More advertisements" data-count={supportingAds.length} className={`buyer-ad-supporting grid min-w-0 grid-cols-1 gap-4 ${current ? '' : 'mx-auto w-full max-w-md'}`}>
                    {supportingAds.map((banner) => (
                        <div
                            key={banner.id}
                            className="rounded-2xl overflow-hidden border border-slate-200/90 shadow-[0_4px_16px_rgba(15,23,42,0.06)] hover:shadow-[0_12px_28px_rgba(15,23,42,0.12)] hover:-translate-y-1 transition-all duration-300 bg-slate-100 group"
                        >
                            <PictureLink
                                href={banner.href}
                                interactive={interactive}
                                onClick={() => measurement.click(banner.measurement)}
                                onAuxClick={(event) => { if (event.button === 1) measurement.click(banner.measurement); }}
                                aria-label={banner.title}
                                className="block min-w-0 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42]"
                            >
                                <MeasuredArtwork banner={banner} session={measurement} interactive={interactive}>
                                    <img
                                        src={banner.image}
                                        alt={banner.alt_text}
                                        width={banner.width}
                                        height={banner.height}
                                        onError={() => failedImage(banner.image)}
                                        loading="lazy"
                                        decoding="async"
                                        className="block h-auto w-full object-cover transition-transform duration-700 ease-out group-hover:scale-[1.03]"
                                    />
                                </MeasuredArtwork>
                            </PictureLink>
                        </div>
                    ))}
                </div>
            )}
        </section>
    );
}
