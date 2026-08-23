import React, { useState, useEffect } from 'react';
import BagooLogo from '@/Components/BagooLogo';

interface Props {
    minDisplayTime?: number; // Minimum display time in ms (default 900ms)
    onComplete?: () => void;
}

export default function BagooLoadingScreen({ minDisplayTime = 1000, onComplete }: Props) {
    const [isVisible, setIsVisible] = useState(true);
    const [isFading, setIsFading] = useState(false);

    useEffect(() => {
        const handleComplete = () => {
            setTimeout(() => {
                setIsFading(true);
                onComplete?.();
                setTimeout(() => {
                    setIsVisible(false);
                }, 600); // fade duration
            }, minDisplayTime);
        };

        if (document.readyState === 'complete') {
            handleComplete();
        } else {
            window.addEventListener('load', handleComplete);
            // Fallback timeout in case load event already fired
            const timer = setTimeout(handleComplete, minDisplayTime + 500);
            return () => {
                window.removeEventListener('load', handleComplete);
                clearTimeout(timer);
            };
        }
    }, [minDisplayTime]);

    if (!isVisible) return null;

    const letters = ['B', 'a', 'g', 'o', 'o'];

    return (
        <div
            className={`fixed inset-0 z-[9999] bg-[#0B0F19] text-white flex flex-col items-center justify-center transition-all duration-600 ease-out ${
                isFading ? 'opacity-0 scale-105 pointer-events-none backdrop-blur-md' : 'opacity-100'
            }`}
        >
            {/* Background Ambient Glow */}
            <div className="absolute w-96 h-96 bg-[#E00D42]/15 rounded-full blur-3xl pointer-events-none animate-pulse"></div>

            <div className="relative flex flex-col items-center space-y-6">
                
                {/* Logo Mark */}
                <div className="relative">
                    <BagooLogo className="w-12 h-12 shadow-2xl animate-bounce" rounded="rounded-2xl" />
                </div>

                {/* Animated Jumping Letters for "Bagoo" */}
                <div className="flex items-center gap-1.5 font-black text-4xl sm:text-5xl tracking-tight select-none font-sans">
                    {letters.map((char, index) => (
                        <span
                            key={index}
                            className="bagoo-letter inline-block"
                            style={{
                                animationDelay: `${index * 0.15}s`,
                            }}
                        >
                            {char}
                        </span>
                    ))}
                    <span className="text-xl sm:text-2xl font-mono text-[#E00D42] font-black ml-1 uppercase tracking-widest animate-pulse">
                        PH
                    </span>
                </div>

                {/* Loading Status Bar / Tagline */}
                <div className="flex flex-col items-center space-y-2 pt-2">
                    <div className="w-32 bg-slate-800/80 h-1 rounded-full overflow-hidden border border-white/5">
                        <div className="bagoo-progress-bar h-full bg-gradient-to-r from-transparent via-[#E00D42] to-transparent rounded-full"></div>
                    </div>
                    <span className="text-[11px] font-mono font-bold tracking-widest text-slate-400 uppercase">
                        Loading Marketplace
                    </span>
                </div>

            </div>

            {/* Custom Animation Styles */}
            <style>{`
                @keyframes bagooLetterJump {
                    0%, 100% {
                        transform: translateY(0px) scale(1);
                        color: rgba(255, 255, 255, 0.35);
                        text-shadow: none;
                    }
                    35% {
                        transform: translateY(-16px) scale(1.12);
                        color: #FFFFFF;
                        text-shadow: 0 0 20px rgba(224, 13, 66, 0.8), 0 0 40px rgba(255, 255, 255, 0.4);
                    }
                    60% {
                        transform: translateY(-4px) scale(1.04);
                        color: #E00D42;
                        text-shadow: 0 0 14px rgba(224, 13, 66, 0.6);
                    }
                    80% {
                        transform: translateY(0px) scale(1);
                        color: rgba(255, 255, 255, 0.45);
                    }
                }

                @keyframes bagooProgressSlide {
                    0% {
                        transform: translateX(-100%);
                    }
                    100% {
                        transform: translateX(100%);
                    }
                }

                .bagoo-letter {
                    animation: bagooLetterJump 1.4s cubic-bezier(0.28, 0.84, 0.42, 1) infinite;
                }

                .bagoo-progress-bar {
                    animation: bagooProgressSlide 1.2s ease-in-out infinite;
                }
            `}</style>
        </div>
    );
}
