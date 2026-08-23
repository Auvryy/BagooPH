import React, { PropsWithChildren } from 'react';
import { Link } from '@inertiajs/react';
import GrainOverlay from '@/Components/GrainOverlay';

interface Props {
    title?: string;
    subtitle?: string;
    headerBadge?: string;
}

export default function GuestLayout({ children, title, subtitle, headerBadge }: PropsWithChildren<Props>) {
    return (
        <div className="relative min-h-screen bg-[#ECEAE5] text-[#111111] font-sans selection:bg-[#E00D42] selection:text-white flex flex-col justify-between p-4 sm:p-6 lg:p-8 overflow-hidden">
            {/* Grain & Noise Overlay */}
            <GrainOverlay />

            {/* Precision Crosshair Markers */}
            <span className="absolute top-4 left-4 text-black/30 font-mono text-xs select-none">+</span>
            <span className="absolute top-4 right-4 text-black/30 font-mono text-xs select-none">+</span>
            <span className="absolute bottom-4 left-4 text-black/30 font-mono text-xs select-none">+</span>
            <span className="absolute bottom-4 right-4 text-black/30 font-mono text-xs select-none">+</span>

            {/* Top Minimal Navigation Bar */}
            <header className="relative z-20 w-full max-w-5xl mx-auto flex items-center justify-between font-mono text-xs py-2">
                <Link href="/" className="group flex items-center gap-2">
                    <span className="text-xl font-black tracking-tighter text-black flex items-center">
                        <span>B</span>
                        <span className="relative inline-flex items-center justify-center">
                            A
                            <span className="absolute top-[32%] left-[48%] -translate-x-1/2 -translate-y-1/2 w-1.5 h-1.5 rounded-full bg-[#E00D42] shadow-xs animate-pulse"></span>
                        </span>
                        <span>GOO</span>
                    </span>
                    <span className="text-black/30">/</span>
                    <span className="text-[10px] text-black/60 font-bold uppercase tracking-wider hidden sm:inline">
                        AUTHENTICATION GATEWAY
                    </span>
                </Link>

                <div className="flex items-center gap-4 text-[11px]">
                    <Link 
                        href="/" 
                        className="text-black/60 hover:text-black transition uppercase font-bold tracking-wider"
                    >
                        ← Back to Overview
                    </Link>
                </div>
            </header>

            {/* Main Auth Container */}
            <main className="relative z-20 w-full max-w-md mx-auto my-auto py-6">
                <div className="bg-white rounded-2xl border border-black/15 p-6 sm:p-8 shadow-xl relative overflow-hidden">
                    {/* Top Red Accent Line */}
                    <div className="absolute top-0 left-0 right-0 h-1 bg-[#E00D42]"></div>

                    {/* Header Title Section */}
                    {(title || headerBadge) && (
                        <div className="mb-6 pb-4 border-b border-black/10">
                            {headerBadge && (
                                <span className="inline-block px-2 py-0.5 mb-2 rounded bg-[#E00D42]/10 text-[#E00D42] font-mono text-[9px] font-bold uppercase tracking-widest border border-[#E00D42]/20">
                                    {headerBadge}
                                </span>
                            )}
                            {title && (
                                <h1 className="text-2xl font-black tracking-tight text-black font-sans">
                                    {title}
                                </h1>
                            )}
                            {subtitle && (
                                <p className="text-xs text-black/60 font-mono mt-1 uppercase">
                                    {subtitle}
                                </p>
                            )}
                        </div>
                    )}

                    {children}
                </div>
            </main>

            {/* Bottom Minimal Footer */}
            <footer className="relative z-20 w-full max-w-5xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-2 font-mono text-[10px] text-black/50 py-2 border-t border-black/10">
                <div className="flex items-center gap-2">
                    <span className="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                    <span>BAGOO-PH SECURE AUTHENTICATION SYSTEM</span>
                </div>
                <span>PHILIPPINES // METRO MANILA</span>
            </footer>
        </div>
    );
}
