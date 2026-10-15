import { useState } from 'react';
import { ChevronLeft, ChevronRight } from 'lucide-react';

export interface Advertisement {
    id: number;
    title: string;
    image: string;
}

export default function BuyerAdvertisements({ banners }: { banners: Advertisement[] }) {
    const [selected, setSelected] = useState(0);
    const currentIndex = selected % Math.max(banners.length, 1);
    const current = banners[currentIndex];

    if (!current) return null;

    const move = (direction: number) => {
        setSelected((index) => (index + direction + banners.length) % banners.length);
    };

    return (
        <section
            aria-label="Bagoo advertisements"
            aria-roledescription="carousel"
            className="relative left-1/2 w-screen -translate-x-1/2"
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

            <p aria-live="polite" className="sr-only">{current.title}</p>

            {banners.length > 1 && (
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
                        {banners.map((banner, index) => (
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
        </section>
    );
}
