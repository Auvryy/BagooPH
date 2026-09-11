import React, { PropsWithChildren } from 'react';
import { Link } from '@inertiajs/react';
import BagooLogo from '@/Components/BagooLogo';
import { getDomainUrl } from '@/utils/domain';

interface Props {
    title?: string;
    subtitle?: string;
    headerBadge?: string;
    formPosition?: 'left' | 'right';
    imageSrc?: string;
    imageAlt?: string;
    imageBadge?: string;
    imageHeadline?: string;
    imageDescription?: string;
    showMarketplaceLink?: boolean;
    maxWidth?: 'sm' | 'md' | 'lg' | 'xl' | '2xl' | '3xl' | '4xl' | '5xl' | '6xl' | '7xl';
    noCard?: boolean;
}

export default function GuestLayout({ 
    children, 
    title, 
    subtitle, 
    headerBadge, 
    formPosition = 'left',
    imageSrc,
    imageAlt,
    imageBadge,
    imageHeadline,
    imageDescription,
    maxWidth = 'md',
    showMarketplaceLink = true,
    noCard = false,
}: PropsWithChildren<Props>) {
    // If an image is provided, render the full-viewport split-screen layout
    if (imageSrc) {
        const isFormLeft = formPosition === 'left';

        return (
            <div className="min-h-screen bg-white text-[#0F172A] font-sans selection:bg-[#E00D42] selection:text-white grid grid-cols-1 lg:grid-cols-12 overflow-x-hidden">
                {/* FORM COLUMN */}
                <div className={`col-span-1 lg:col-span-6 xl:col-span-5 flex flex-col justify-between p-6 sm:p-10 lg:p-14 bg-white ${
                    isFormLeft ? 'order-1' : 'order-1 lg:order-2'
                }`}>
                    {/* Brand Header */}
                    <div className="flex items-center justify-between pb-8">
                        <Link href="/" className="group flex items-center gap-2.5">
                            <BagooLogo className="w-8 h-8 group-hover:scale-105 transition-transform duration-200" rounded="rounded-lg" />
                            <div className="flex items-center gap-1 font-mono text-sm tracking-tight">
                                <span className="font-bold text-slate-900">Bagoo</span>
                                <span className="text-[#E00D42] font-black">PH</span>
                            </div>
                        </Link>

                        {showMarketplaceLink && (
                            <a 
                                href={getDomainUrl('buyer', '/')} 
                                className="text-slate-500 hover:text-slate-900 font-mono text-xs transition underline-offset-4 hover:underline"
                            >
                                Marketplace →
                            </a>
                        )}
                    </div>

                    {/* Form Body */}
                    <div className="my-auto py-6 max-w-md w-full mx-auto">
                        {headerBadge && (
                            <span className="inline-block px-2.5 py-1 mb-3 rounded-md bg-slate-100 text-slate-700 font-mono text-[10px] font-bold uppercase tracking-wider">
                                {headerBadge}
                            </span>
                        )}
                        {title && (
                            <h1 className="text-2xl sm:text-3xl font-bold tracking-tight text-slate-900 font-sans">
                                {title}
                            </h1>
                        )}
                        {subtitle && (
                            <p className="text-xs sm:text-sm text-slate-500 font-sans mt-1.5 mb-6">
                                {subtitle}
                            </p>
                        )}

                        {children}
                    </div>

                    {/* Footer */}
                    <div className="pt-8 text-slate-400 font-mono text-[11px] flex items-center justify-between border-t border-slate-100">
                        <span>BagooPH // Secure Authentication</span>
                        <span>© {new Date().getFullYear()}</span>
                    </div>
                </div>

                {/* ARTWORK / 3D VISUAL COLUMN */}
                <div className={`hidden lg:flex lg:col-span-6 xl:col-span-7 bg-[#F8FAFC] relative overflow-hidden items-center justify-center p-12 ${
                    isFormLeft 
                        ? 'order-2 border-l border-slate-200' 
                        : 'order-2 lg:order-1 border-r border-slate-200'
                }`}>
                    {/* Official Bagoo Floating Badge */}
                    <div className="absolute top-8 left-8 z-10 flex items-center gap-2.5 bg-white border border-slate-200 px-3.5 py-2 rounded-xl shadow-xs font-mono text-xs">
                        <BagooLogo className="w-5 h-5" rounded="rounded-md" />
                        <span className="font-bold text-slate-900">BagooPH</span>
                        <span className="text-slate-300">/</span>
                        <span className="text-[11px] text-slate-600 font-semibold uppercase tracking-wider">
                            {imageBadge || 'Official Service'}
                        </span>
                    </div>

                    {/* Centered Artwork Showcase */}
                    <div className="flex flex-col items-center justify-center max-w-lg text-center">
                        <img 
                            src={imageSrc} 
                            alt={imageAlt || 'BagooPH Auth Visual'} 
                            className="w-full max-h-[55vh] object-contain rounded-2xl border border-slate-200/80 shadow-xs select-none"
                        />
                        {imageHeadline && (
                            <h3 className="text-lg font-bold text-slate-900 mt-6 font-sans">
                                {imageHeadline}
                            </h3>
                        )}
                        {imageDescription && (
                            <p className="text-xs text-slate-500 mt-1 max-w-sm font-sans">
                                {imageDescription}
                            </p>
                        )}
                    </div>
                </div>
            </div>
        );
    }

    // Centered Fallback (for email verification, password reset, etc.)
    const maxWidthClass = {
        sm: 'max-w-sm',
        md: 'max-w-md',
        lg: 'max-w-lg',
        xl: 'max-w-xl',
        '2xl': 'max-w-2xl',
        '3xl': 'max-w-3xl',
        '4xl': 'max-w-4xl',
        '5xl': 'max-w-5xl',
        '6xl': 'max-w-6xl',
        '7xl': 'max-w-7xl',
    }[maxWidth];

    return (
        <div className="min-h-screen bg-[#F8FAFC] text-[#0F172A] font-sans selection:bg-[#E00D42] selection:text-white flex flex-col justify-between p-4 sm:p-6 lg:p-8">
            <header className="w-full max-w-6xl mx-auto flex items-center justify-between font-mono text-xs py-2">
                <Link href="/" className="group flex items-center gap-2.5">
                    <BagooLogo className="w-8 h-8 group-hover:scale-105 transition-transform duration-200" rounded="rounded-lg" />
                    <div className="flex items-center gap-1 font-mono text-sm tracking-tight">
                        <span className="font-bold text-slate-900">Bagoo</span>
                        <span className="text-[#E00D42] font-black">PH</span>
                    </div>
                </Link>

                {showMarketplaceLink && (
                    <a 
                        href={getDomainUrl('buyer', '/')} 
                        className="text-slate-500 hover:text-slate-900 transition font-mono text-xs underline-offset-4 hover:underline"
                    >
                        Marketplace →
                    </a>
                )}
            </header>

            <main className={`w-full ${maxWidthClass} mx-auto my-auto py-6`}>
                {noCard ? (
                    children
                ) : (
                    <div className="bg-white rounded-2xl border border-slate-200 shadow-xs p-6 sm:p-8">
                        {(title || headerBadge) && (
                            <div className="mb-6 pb-4 border-b border-slate-100">
                                {headerBadge && (
                                    <span className="inline-block px-2.5 py-1 mb-2 rounded-md bg-slate-100 text-slate-700 font-mono text-[10px] font-bold uppercase tracking-wider">
                                        {headerBadge}
                                    </span>
                                )}
                                {title && (
                                    <h1 className="text-2xl font-bold tracking-tight text-slate-900 font-sans">
                                        {title}
                                    </h1>
                                )}
                                {subtitle && (
                                    <p className="text-xs text-slate-500 font-sans mt-1">
                                        {subtitle}
                                    </p>
                                )}
                            </div>
                        )}

                        {children}
                    </div>
                )}
            </main>

            <footer className="w-full max-w-6xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-2 font-mono text-[10px] text-slate-400 py-3 border-t border-slate-200">
                <span>BAGOO SECURE GATEWAY // ENCRYPTED SESSION</span>
                <span>METRO MANILA COD LOGISTICS // 10% FLAT PLATFORM STANDARD</span>
            </footer>
        </div>
    );
}
