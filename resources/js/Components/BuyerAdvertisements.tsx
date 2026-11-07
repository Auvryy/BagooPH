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

const defaultSlides: Advertisement[] = [
    {
        id: 991,
        title: 'Bagoo 11.11 Mega Sale — Up to 90% Off & Free Shipping',
        image: '/images/buyer/advertisements/mega-sale.webp',
        alt_text: 'Bagoo 11.11 Mega Sale Promotion',
        width: 1880,
        height: 880,
        slot: 'main_1',
        placement: 'main' as const,
        href: '#flash-deals',
    },
    {
        id: 992,
        title: 'Mega Carnival Grand Celebration — Extra 15% Vouchers',
        image: '/images/buyer/advertisements/carnival.webp',
        alt_text: 'Mega Carnival Grand Celebration',
        width: 1880,
        height: 880,
        slot: 'main_2',
        placement: 'main' as const,
        href: '/vouchers',
    },
    {
        id: 993,
        title: 'Electronics & Lifestyle Frenzy — Verified Gear & Audio',
        image: '/images/buyer/advertisements/frenzy.webp',
        alt_text: 'Electronics Frenzy Event',
        width: 1880,
        height: 880,
        slot: 'main_3',
        placement: 'main' as const,
        href: '#product-feed',
    },
    {
        id: 994,
        title: 'Payday Super Savers — Direct Merchant Discounts',
        image: '/images/buyer/advertisements/payday.webp',
        alt_text: 'Payday Super Savers Promotion',
        width: 1880,
        height: 880,
        slot: 'main_4',
        placement: 'main' as const,
        href: '#flash-deals',
    },
    {
        id: 995,
        title: 'Curated Department Collections — 14 Official Niches',
        image: '/images/buyer/advertisements/collection.webp',
        alt_text: 'Curated Department Collections',
        width: 1880,
        height: 880,
        slot: 'main_5',
        placement: 'main' as const,
        href: '/top-products',
    },
];

function getSlideTheme(image: string) {
    if (image.includes('carnival')) {
        return {
            glow: 'from-[#4C0519]/70 via-[#9F1239]/60 to-[#E11D48]/50',
            shadow: '0 24px 56px -12px rgba(159, 18, 57, 0.45)',
        };
    }
    if (image.includes('frenzy')) {
        return {
            glow: 'from-[#3B0764]/70 via-[#7E22CE]/60 to-[#C026D3]/50',
            shadow: '0 24px 56px -12px rgba(126, 34, 206, 0.42)',
        };
    }
    if (image.includes('payday')) {
        return {
            glow: 'from-[#064E3B]/70 via-[#059669]/60 to-[#10B981]/50',
            shadow: '0 24px 56px -12px rgba(5, 150, 105, 0.42)',
        };
    }
    if (image.includes('collection')) {
        return {
            glow: 'from-[#78350F]/70 via-[#D97706]/60 to-[#F59E0B]/50',
            shadow: '0 24px 56px -12px rgba(217, 119, 6, 0.42)',
        };
    }
    // Default / Mega Sale
    return {
        glow: 'from-[#7A0623]/75 via-[#E00D42]/65 to-[#B91C1C]/55',
        shadow: '0 24px 56px -12px rgba(224, 13, 66, 0.48)',
    };
}

export default function BuyerAdvertisements({ banners, interactive = true, initialSelectedId }: { banners: Advertisement[]; interactive?: boolean; initialSelectedId?: number }) {
    const measurement = useRef(new AdvertisementMeasurementSession()).current;
    const [failed, setFailed] = useState<string[]>([]);
    const available = banners.filter((banner) => !failed.includes(banner.image));
    
    // Combine passed banners with default rich hero campaigns
    let slides = available.length > 0 
        ? [...available, ...defaultSlides.filter(ds => !available.some(a => a.image === ds.image))]
        : defaultSlides;

    const [selected, setSelected] = useState(() => Math.max(0, slides.findIndex((banner) => banner.id === initialSelectedId)));
    const [isHovered, setIsHovered] = useState(false);
    const currentIndex = selected % Math.max(slides.length, 1);
    const current = slides[currentIndex];

    // Automatic slide cycling slightly faster (3000ms instead of 5000ms), pauses on hover
    useEffect(() => {
        if (slides.length <= 1 || isHovered) return;
        const interval = setInterval(() => {
            setSelected((prev) => (prev + 1) % slides.length);
        }, 3000);
        return () => clearInterval(interval);
    }, [slides.length, isHovered]);

    if (!current) return null;
    const failedImage = (image: string) => setFailed((images) => images.includes(image) ? images : [...images, image]);

    const move = (direction: number) => {
        setSelected((index) => (index + direction + slides.length) % slides.length);
    };

    const theme = getSlideTheme(current.image);

    return (
        <section
            aria-label="Bagoo event promotion"
            className="relative w-full overflow-visible mb-5 sm:mb-6"
        >
            {/* Subtle, big-spread ambient shadow glowing with the event banner color below the image */}
            <div
                aria-hidden="true"
                className={`absolute -inset-x-2 sm:-inset-x-4 -bottom-6 sm:-bottom-8 h-24 sm:h-36 bg-gradient-to-r ${theme.glow} blur-2xl sm:blur-3xl rounded-[3rem] -z-10 pointer-events-none transition-all duration-700 opacity-80 sm:opacity-90`}
            />

            {/* Single full-width hero banner without side gaps, eating space */}
            <div
                role="region"
                aria-roledescription="carousel"
                aria-label="Featured platform event"
                className="relative w-full overflow-hidden rounded-none sm:rounded-2xl md:rounded-3xl border-y sm:border border-slate-200/80 bg-slate-950 group transition-all duration-700"
                style={{ boxShadow: theme.shadow }}
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
                            className="block w-full h-[180px] sm:h-[260px] md:h-[340px] lg:h-[400px] object-cover transition-transform duration-700 ease-out group-hover:scale-[1.01]"
                        />
                    </MeasuredArtwork>
                </PictureLink>

                <p aria-live="polite" className="sr-only">{current.title}</p>

                {slides.length > 1 && (
                    <>
                        <button
                            type="button"
                            onClick={() => move(-1)}
                            aria-label="Previous event"
                            className="absolute left-2.5 sm:left-4 top-1/2 flex h-8 w-8 sm:h-10 sm:w-10 -translate-y-1/2 items-center justify-center rounded-full bg-black/40 hover:bg-[#E00D42] text-white backdrop-blur-md shadow-lg transition-all focus-visible:outline focus-visible:outline-2 focus-visible:outline-white opacity-0 group-hover:opacity-100 cursor-pointer"
                        >
                            <ChevronLeft className="h-5 w-5 sm:h-6 sm:w-6" aria-hidden="true" />
                        </button>
                        <button
                            type="button"
                            onClick={() => move(1)}
                            aria-label="Next event"
                            className="absolute right-2.5 sm:right-4 top-1/2 flex h-8 w-8 sm:h-10 sm:w-10 -translate-y-1/2 items-center justify-center rounded-full bg-black/40 hover:bg-[#E00D42] text-white backdrop-blur-md shadow-lg transition-all focus-visible:outline focus-visible:outline-2 focus-visible:outline-white opacity-0 group-hover:opacity-100 cursor-pointer"
                        >
                            <ChevronRight className="h-5 w-5 sm:h-6 sm:w-6" aria-hidden="true" />
                        </button>
                        <div role="group" aria-label="Choose an advertisement" className="absolute bottom-3 left-1/2 flex -translate-x-1/2 gap-1.5 bg-black/50 backdrop-blur-md border border-white/20 px-3 py-1.5 rounded-full shadow-lg">
                            {slides.map((banner, index) => (
                                <button
                                    key={banner.id}
                                    type="button"
                                    onClick={() => setSelected(index)}
                                    aria-label={`Show event ${index + 1}: ${banner.title}`}
                                    aria-pressed={currentIndex === index}
                                    className="flex h-3.5 w-3.5 items-center justify-center rounded-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42] cursor-pointer"
                                >
                                    <span
                                        aria-hidden="true"
                                        className={`h-1.5 rounded-full transition-all duration-300 ${currentIndex === index ? 'w-5 bg-[#E00D42] shadow-[0_0_8px_#E00D42]' : 'w-1.5 bg-white/50 hover:bg-white/80'}`}
                                    />
                                </button>
                            ))}
                        </div>
                    </>
                )}
            </div>
        </section>
    );
}
