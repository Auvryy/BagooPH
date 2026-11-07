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

const defaultMainSlides: Advertisement[] = [
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
        id: 993,
        title: 'Electronics & Lifestyle Frenzy — Verified Gear & Audio',
        image: '/images/buyer/advertisements/frenzy.webp',
        alt_text: 'Electronics Frenzy Event',
        width: 1880,
        height: 880,
        slot: 'main_2',
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
        slot: 'main_3',
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
        slot: 'main_4',
        placement: 'main' as const,
        href: '/top-products',
    },
];

const defaultSideBanners: Advertisement[] = [
    {
        id: 992,
        title: 'Anniversary Gala Mega Carnival — Extra 15% Vouchers & Up to 90% Off',
        image: '/images/buyer/advertisements/carnival.webp',
        alt_text: 'Anniversary Gala Mega Carnival',
        width: 1880,
        height: 880,
        slot: 'side_1',
        placement: 'side' as const,
        href: '/vouchers',
    },
    {
        id: 996,
        title: 'Grand Celebration Mega Carnival — Free Shipping ₱0 Min Spend',
        image: '/images/buyer/advertisements/carnival.webp',
        alt_text: 'Mega Carnival Grand Celebration Free Shipping',
        width: 1880,
        height: 880,
        slot: 'side_2',
        placement: 'side' as const,
        href: '#flash-deals',
    },
];

function getSlideTheme(image: string) {
    if (image.includes('carnival')) {
        return {
            glow: 'from-[#4C0519]/70 via-[#9F1239]/60 to-[#E11D48]/50',
            shadow: '0 24px 50px -12px rgba(159, 18, 57, 0.42)',
        };
    }
    if (image.includes('frenzy')) {
        return {
            glow: 'from-[#3B0764]/70 via-[#7E22CE]/60 to-[#C026D3]/50',
            shadow: '0 24px 50px -12px rgba(126, 34, 206, 0.40)',
        };
    }
    if (image.includes('payday')) {
        return {
            glow: 'from-[#064E3B]/70 via-[#059669]/60 to-[#10B981]/50',
            shadow: '0 24px 50px -12px rgba(5, 150, 105, 0.40)',
        };
    }
    if (image.includes('collection')) {
        return {
            glow: 'from-[#78350F]/70 via-[#D97706]/60 to-[#F59E0B]/50',
            shadow: '0 24px 50px -12px rgba(217, 119, 6, 0.40)',
        };
    }
    // Default / 11.11 Mega Sale (Crimson)
    return {
        glow: 'from-[#7A0623]/75 via-[#E00D42]/65 to-[#B91C1C]/55',
        shadow: '0 24px 50px -12px rgba(224, 13, 66, 0.45)',
    };
}

export default function BuyerAdvertisements({ banners, interactive = true, initialSelectedId }: { banners: Advertisement[]; interactive?: boolean; initialSelectedId?: number }) {
    const measurement = useRef(new AdvertisementMeasurementSession()).current;
    const [failed, setFailed] = useState<string[]>([]);
    const available = banners.filter((banner) => !failed.includes(banner.image));
    
    // Main carousel slides
    const dbMain = available.filter(b => b.placement === 'main');
    const slides = dbMain.length > 0 ? dbMain : defaultMainSlides;

    // 2 stacked side banners
    const dbSide = available.filter(b => b.placement === 'side');
    const sideBanners = dbSide.length >= 2 ? dbSide.slice(0, 2) : defaultSideBanners;

    const [selected, setSelected] = useState(() => Math.max(0, slides.findIndex((banner) => banner.id === initialSelectedId)));
    const [isHovered, setIsHovered] = useState(false);
    const currentIndex = selected % Math.max(slides.length, 1);
    const current = slides[currentIndex];

    // Automatic slide cycling slightly faster (3200ms instead of 5000ms), pauses on hover
    useEffect(() => {
        if (slides.length <= 1 || isHovered) return;
        const interval = setInterval(() => {
            setSelected((prev) => (prev + 1) % slides.length);
        }, 3200);
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
            aria-label="Bagoo event promotions"
            className="relative w-full overflow-visible mb-4 sm:mb-5"
        >
            {/* Subtle, big-spread ambient shadow glowing with the event banner color below the entire 3-image layout */}
            <div
                aria-hidden="true"
                className={`absolute -inset-x-2 sm:-inset-x-4 -bottom-6 sm:-bottom-8 h-24 sm:h-36 bg-gradient-to-r ${theme.glow} blur-2xl sm:blur-3xl rounded-[3rem] -z-10 pointer-events-none transition-all duration-700 opacity-80 sm:opacity-90`}
            />

            {/* 3-Image Layout: 1 large carousel (2/3) + 2 stacked side banners (1/3), edge-to-edge full bleed with zero corner radius where touching header/outer edges */}
            <div className="grid grid-cols-1 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)] gap-2 sm:gap-2.5 items-stretch">
                
                {/* 1. Main Carousel Hero Banner:
                    - Top and left touch header and screen wall -> NO radius (rounded-t-none rounded-l-none)
                    - Inner slice facing right side banners -> smooth rounded-r-xl
                    - Bottom facing page -> smooth rounded-b-xl
                */}
                <div
                    role="region"
                    aria-roledescription="carousel"
                    aria-label="Featured platform event"
                    className="relative w-full overflow-hidden rounded-none lg:rounded-r-2xl rounded-b-xl lg:rounded-bl-none border-b sm:border-y lg:border-r border-slate-200/80 bg-slate-950 group transition-all duration-700 flex flex-col justify-center"
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
                        className="block w-full h-full focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-[#E00D42]"
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
                                className="block w-full h-full object-cover transition-transform duration-700 ease-out group-hover:scale-[1.01]"
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
                                className="absolute left-2.5 sm:left-3.5 top-1/2 flex h-8 w-8 sm:h-9 sm:w-9 -translate-y-1/2 items-center justify-center rounded-full bg-black/45 hover:bg-[#E00D42] text-white backdrop-blur-md shadow-md transition-all focus-visible:outline focus-visible:outline-2 focus-visible:outline-white opacity-0 group-hover:opacity-100 cursor-pointer"
                            >
                                <ChevronLeft className="h-5 w-5" aria-hidden="true" />
                            </button>
                            <button
                                type="button"
                                onClick={() => move(1)}
                                aria-label="Next event"
                                className="absolute right-2.5 sm:right-3.5 top-1/2 flex h-8 w-8 sm:h-9 sm:w-9 -translate-y-1/2 items-center justify-center rounded-full bg-black/45 hover:bg-[#E00D42] text-white backdrop-blur-md shadow-md transition-all focus-visible:outline focus-visible:outline-2 focus-visible:outline-white opacity-0 group-hover:opacity-100 cursor-pointer"
                            >
                                <ChevronRight className="h-5 w-5" aria-hidden="true" />
                            </button>
                            <div role="group" aria-label="Choose an advertisement" className="absolute bottom-2.5 left-1/2 flex -translate-x-1/2 gap-1.5 bg-black/50 backdrop-blur-md border border-white/20 px-2.5 py-1 rounded-full shadow-md">
                                {slides.map((banner, index) => (
                                    <button
                                        key={banner.id}
                                        type="button"
                                        onClick={() => setSelected(index)}
                                        aria-label={`Show event ${index + 1}: ${banner.title}`}
                                        aria-pressed={currentIndex === index}
                                        className="flex h-3 w-3 items-center justify-center rounded-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42] cursor-pointer"
                                    >
                                        <span
                                            aria-hidden="true"
                                            className={`h-1.5 rounded-full transition-all duration-300 ${currentIndex === index ? 'w-4.5 bg-[#E00D42] shadow-[0_0_8px_#E00D42]' : 'w-1.5 bg-white/50 hover:bg-white/80'}`}
                                        />
                                    </button>
                                ))}
                            </div>
                        </>
                    )}
                </div>

                {/* 2. Right Column: 2 Stacked Side Banners
                    - Touching header or right wall -> NO radius (rounded-t-none rounded-r-none)
                    - Logical inner slicing corner facing main carousel or between banners -> smooth rounded-l-2xl / rounded-b-xl
                */}
                <div className="hidden lg:grid grid-rows-2 gap-2 sm:gap-2.5 min-w-0">
                    {/* Top Side Banner: touches header & right wall -> rounded-t-none rounded-r-none, inner left/bottom has radius */}
                    {sideBanners[0] && (
                        <div
                            className="relative overflow-hidden rounded-l-2xl rounded-t-none rounded-r-none rounded-b-lg border-b border-l border-slate-200/80 bg-slate-950 shadow-sm hover:shadow-md transition-all duration-300 group flex items-center justify-center"
                        >
                            <PictureLink
                                href={sideBanners[0].href}
                                interactive={interactive}
                                onClick={() => measurement.click(sideBanners[0].measurement)}
                                onAuxClick={(event) => { if (event.button === 1) measurement.click(sideBanners[0].measurement); }}
                                aria-label={sideBanners[0].title}
                                className="block w-full h-full focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-[#E00D42]"
                            >
                                <MeasuredArtwork banner={sideBanners[0]} session={measurement} interactive={interactive}>
                                    <img
                                        src={sideBanners[0].image}
                                        alt={sideBanners[0].alt_text}
                                        width={sideBanners[0].width}
                                        height={sideBanners[0].height}
                                        onError={() => failedImage(sideBanners[0].image)}
                                        loading="eager"
                                        className="block w-full h-full object-cover transition-transform duration-700 ease-out group-hover:scale-[1.02]"
                                    />
                                </MeasuredArtwork>
                            </PictureLink>
                        </div>
                    )}

                    {/* Bottom Side Banner: touches right wall -> rounded-r-none, inner left/top has radius, bottom facing down has slight curve */}
                    {sideBanners[1] && (
                        <div
                            className="relative overflow-hidden rounded-l-2xl rounded-r-none rounded-t-lg rounded-b-none border-t border-l border-slate-200/80 bg-slate-950 shadow-sm hover:shadow-md transition-all duration-300 group flex items-center justify-center"
                        >
                            <PictureLink
                                href={sideBanners[1].href}
                                interactive={interactive}
                                onClick={() => measurement.click(sideBanners[1].measurement)}
                                onAuxClick={(event) => { if (event.button === 1) measurement.click(sideBanners[1].measurement); }}
                                aria-label={sideBanners[1].title}
                                className="block w-full h-full focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-[#E00D42]"
                            >
                                <MeasuredArtwork banner={sideBanners[1]} session={measurement} interactive={interactive}>
                                    <img
                                        src={sideBanners[1].image}
                                        alt={sideBanners[1].alt_text}
                                        width={sideBanners[1].width}
                                        height={sideBanners[1].height}
                                        onError={() => failedImage(sideBanners[1].image)}
                                        loading="eager"
                                        className="block w-full h-full object-cover transition-transform duration-700 ease-out group-hover:scale-[1.02]"
                                    />
                                </MeasuredArtwork>
                            </PictureLink>
                        </div>
                    )}
                </div>

            </div>
        </section>
    );
}
