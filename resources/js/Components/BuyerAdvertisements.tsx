import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowRight, ChevronLeft, ChevronRight } from 'lucide-react';

export interface Advertisement {
    id: number;
    title: string;
    image: string;
}

export default function BuyerAdvertisements({ banners }: { banners: Advertisement[] }) {
    const slides = banners.slice(0, 3);
    const supportingAds = banners.slice(3);
    const [selected, setSelected] = useState(0);
    const currentIndex = selected % Math.max(slides.length, 1);
    const current = slides[currentIndex];

    if (!current) return null;

    const move = (direction: number) => {
        setSelected((currentIndex + direction + slides.length) % slides.length);
    };

    return (
        <section aria-label="Bagoo advertisements" className="space-y-3">
            <div className={`grid min-w-0 gap-3 ${supportingAds.length ? 'lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]' : ''}`}>
                <div
                    role="region"
                    aria-roledescription="carousel"
                    aria-label="Featured advertisements"
                    className="min-w-0 self-start overflow-hidden rounded-lg border border-slate-300 bg-white shadow-sm"
                >
                    <a
                        href="#product-feed"
                        aria-label={`${current.title}: browse products`}
                        className="block focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-[#E00D42]"
                    >
                        <img
                            src={current.image}
                            alt={`Bagoo ${current.title} advertisement`}
                            width={1880}
                            height={880}
                            loading="eager"
                            className="block h-auto w-full"
                        />
                    </a>

                    <div className="flex items-center gap-2 border-t border-slate-300 px-2 py-1.5 sm:px-3">
                        {slides.length > 1 && (
                            <button
                                type="button"
                                onClick={() => move(-1)}
                                aria-label="Previous advertisement"
                                className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-slate-600 hover:bg-slate-100 hover:text-[#E00D42] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42]"
                            >
                                <ChevronLeft className="h-5 w-5" aria-hidden="true" />
                            </button>
                        )}

                        <div className="min-w-0 flex-1 text-center">
                            <p aria-live="polite" className="text-sm font-semibold text-slate-800">
                                {current.title}
                            </p>
                            {slides.length > 1 && (
                                <div role="group" aria-label="Choose an advertisement" className="flex justify-center">
                                    {slides.map((banner, index) => (
                                        <button
                                            key={banner.id}
                                            type="button"
                                            onClick={() => setSelected(index)}
                                            aria-label={`Show advertisement ${index + 1}: ${banner.title}`}
                                            aria-pressed={currentIndex === index}
                                            className="flex h-8 w-8 items-center justify-center rounded-lg focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42]"
                                        >
                                            <span aria-hidden="true" className={`h-1.5 rounded-full ${currentIndex === index ? 'w-5 bg-[#E00D42]' : 'w-1.5 bg-slate-300'}`} />
                                        </button>
                                    ))}
                                </div>
                            )}
                        </div>

                        {slides.length > 1 && (
                            <button
                                type="button"
                                onClick={() => move(1)}
                                aria-label="Next advertisement"
                                className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-slate-600 hover:bg-slate-100 hover:text-[#E00D42] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42]"
                            >
                                <ChevronRight className="h-5 w-5" aria-hidden="true" />
                            </button>
                        )}
                    </div>
                </div>

                {supportingAds.length > 0 && (
                    <div aria-label="More advertisements" className="flex min-w-0 gap-3 overflow-x-auto pb-1 snap-x snap-mandatory sm:grid sm:grid-cols-2 sm:overflow-visible sm:pb-0 lg:grid-cols-1 lg:content-start">
                        {supportingAds.map((banner) => (
                            <a
                                key={banner.id}
                                href="#product-feed"
                                aria-label={`${banner.title}: browse products`}
                                className="block w-[85%] shrink-0 snap-start self-start overflow-hidden rounded-lg border border-slate-300 bg-white shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42] sm:w-auto"
                            >
                                <img
                                    src={banner.image}
                                    alt={`Bagoo ${banner.title} advertisement`}
                                    width={1880}
                                    height={880}
                                    loading="lazy"
                                    decoding="async"
                                    className="block h-auto w-full"
                                />
                                <span className="flex items-center justify-between gap-2 border-t border-slate-300 px-3 py-1 text-xs font-semibold text-slate-700">
                                    {banner.title}
                                    <ArrowRight className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                </span>
                            </a>
                        ))}
                    </div>
                )}
            </div>

            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 text-xs text-slate-600">
                <p>See available vouchers for current offers and conditions.</p>
                <Link
                    href={route('buyer.profile', { tab: 'vouchers' })}
                    className="inline-flex min-h-9 items-center gap-1.5 rounded font-semibold text-[#E00D42] hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42]"
                >
                    View vouchers <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
                </Link>
            </div>
        </section>
    );
}
