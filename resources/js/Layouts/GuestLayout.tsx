import React, { PropsWithChildren } from 'react';
import { Link } from '@inertiajs/react';
import BagooLogo from '@/Components/BagooLogo';
import { getDomainUrl } from '@/utils/domain';

export interface AlternatePortal {
    label?: string;
    subtext?: string;
    href: string;
    buttonText: string;
}

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
    alternatePortal?: AlternatePortal;
    showFooter?: boolean;
}

function AuthEcosystemFooter() {
    return (
        <footer className="bg-white border-t border-slate-300 text-slate-600 font-sans text-xs shrink-0">
            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 grid grid-cols-2 md:grid-cols-4 gap-8">
                {/* Platform Directory */}
                <div>
                    <h5 className="font-bold uppercase text-slate-900 mb-3 tracking-wider text-xs font-mono">
                        Platform Directory
                    </h5>
                    <ul className="space-y-2 text-slate-500">
                        <li>
                            <a href={getDomainUrl('buyer', '/')} className="hover:text-[#E00D42] transition">
                                Marketplace Home
                            </a>
                        </li>
                        <li>
                            <a href={getDomainUrl('buyer', '/buyer/search')} className="hover:text-[#E00D42] transition">
                                Search 14 Departments
                            </a>
                        </li>
                        <li>
                            <a href={getDomainUrl('buyer', '/buyer/orders')} className="hover:text-[#E00D42] transition">
                                Track Purchases
                            </a>
                        </li>
                        <li>
                            <a href={getDomainUrl('buyer', '/buyer/cart')} className="hover:text-[#E00D42] transition">
                                Shopping Bag
                            </a>
                        </li>
                    </ul>
                </div>

                {/* Partner with Us */}
                <div>
                    <h5 className="font-bold uppercase text-slate-900 mb-3 tracking-wider text-xs font-mono">
                        Partner with Us
                    </h5>
                    <ul className="space-y-2 text-slate-500">
                        <li>
                            <a href={getDomainUrl('seller', '/login')} className="hover:text-[#E00D42] font-semibold text-slate-800 transition">
                                Seller Centre Portal
                            </a>
                        </li>
                        <li>
                            <a href={getDomainUrl('seller', '/register')} className="hover:text-[#E00D42] transition">
                                Open a Verified Store
                            </a>
                        </li>
                        <li>
                            <a href={getDomainUrl('courier', '/login')} className="hover:text-emerald-700 font-semibold text-slate-800 transition">
                                Courier Rider Portal
                            </a>
                        </li>
                        <li>
                            <a href={getDomainUrl('courier', '/register')} className="hover:text-emerald-700 transition">
                                Apply as Fleet Driver
                            </a>
                        </li>
                        <li>
                            <a href={getDomainUrl('hub', '/login')} className="hover:text-indigo-700 font-semibold text-slate-800 transition">
                                Logistics Sorting Hub
                            </a>
                        </li>
                    </ul>
                </div>

                {/* Payment Modes */}
                <div>
                    <h5 className="font-bold uppercase text-slate-900 mb-3 tracking-wider text-xs font-mono">
                        Payment Modes
                    </h5>
                    <div className="flex flex-wrap gap-1.5 text-xs font-medium font-mono">
                        <span className="px-2 py-0.5 bg-slate-100 rounded-xs border border-slate-300 text-slate-700">COD</span>
                        <span className="px-2 py-0.5 bg-slate-100 rounded-xs border border-slate-300 text-slate-700">GCash</span>
                        <span className="px-2 py-0.5 bg-slate-100 rounded-xs border border-slate-300 text-slate-700">Maya</span>
                        <span className="px-2 py-0.5 bg-slate-100 rounded-xs border border-slate-300 text-slate-700">Cards</span>
                    </div>
                    <p className="text-slate-400 text-xs mt-3 leading-relaxed">
                        100% Escrow & Inspection Guarantee on all Doorstep Deliveries.
                    </p>
                </div>

                {/* Governance & Security */}
                <div>
                    <h5 className="font-bold uppercase text-slate-900 mb-3 tracking-wider text-xs font-mono">
                        Governance & Security
                    </h5>
                    <ul className="space-y-2 text-slate-500">
                        <li>
                            <a href={getDomainUrl('admin', '/login')} className="hover:text-slate-900 text-slate-600 text-xs transition">
                                Admin Governance Console
                            </a>
                        </li>
                        <li>
                            <span className="text-slate-400">Strict KYC Compliance Verified</span>
                        </li>
                        <li>
                            <span className="text-slate-400">10% Flat Platform Fee Standard</span>
                        </li>
                    </ul>
                </div>
            </div>

            <div className="border-t border-slate-200 py-6 text-center text-xs text-slate-400 font-mono flex flex-col sm:flex-row items-center justify-between max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 gap-2">
                <span>BagooPH // Next-Gen Multi-Role Commerce Ecosystem</span>
                <span>&copy; {new Date().getFullYear()} BagooPH. All Rights Reserved.</span>
            </div>
        </footer>
    );
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
    alternatePortal,
    showFooter = true,
}: PropsWithChildren<Props>) {
    // If an image is provided, render the full-viewport split-screen layout
    if (imageSrc) {
        const isFormLeft = formPosition === 'left';

        return (
            <div className="min-h-screen flex flex-col bg-white text-[#0F172A] font-sans selection:bg-[#E00D42] selection:text-white">
                {/* 100vh Full Viewport Split-Screen Grid */}
                <div className="min-h-[100vh] min-h-[100dvh] flex-1 grid grid-cols-1 lg:grid-cols-12 overflow-x-hidden">
                    {/* FORM COLUMN */}
                    <div className={`col-span-1 lg:col-span-6 xl:col-span-5 flex flex-col justify-between p-6 sm:p-10 lg:p-14 bg-white min-h-full ${
                        isFormLeft ? 'order-1' : 'order-1 lg:order-2'
                    }`}>
                        {/* Constrained container ensures brand header & form body share exact column bounds */}
                        <div className="max-w-md w-full mx-auto flex flex-col justify-between flex-1">
                            {/* Brand Header */}
                            <div className="flex items-center justify-between pb-8 w-full">
                                <Link href="/" className="group flex items-center gap-2.5">
                                    <BagooLogo className="w-8 h-8 group-hover:scale-105 transition-transform duration-200" rounded="rounded-xs" />
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
                            <div className="my-auto py-6 w-full">
                                {headerBadge && (
                                    <span className="inline-block px-2.5 py-1 mb-3 rounded-xs bg-slate-100 text-slate-700 font-mono text-[10px] font-bold uppercase tracking-wider">
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

                                {/* Mobile-Only Alternate Portal Switcher (when image column is hidden) */}
                                {alternatePortal && (
                                    <div className="mt-8 pt-6 border-t border-slate-200 text-center lg:hidden font-sans">
                                        <p className="text-xs text-slate-500">
                                            {alternatePortal.subtext || 'Switch Portal'}{' '}
                                            <a
                                                href={alternatePortal.href}
                                                className="text-slate-900 font-bold hover:text-[#E00D42] transition underline-offset-4 hover:underline ml-1"
                                            >
                                                {alternatePortal.buttonText}
                                            </a>
                                        </p>
                                    </div>
                                )}
                            </div>

                            {/* Inner Column Sub-Footer */}
                            <div className="pt-8 text-slate-400 font-mono text-[11px] flex items-center justify-between border-t border-slate-100 w-full">
                                <span>BagooPH // Secure Authentication</span>
                                <span>&copy; {new Date().getFullYear()}</span>
                            </div>
                        </div>
                    </div>

                    {/* ARTWORK / 3D VISUAL COLUMN */}
                    <div className={`hidden lg:flex lg:col-span-6 xl:col-span-7 bg-[#F8FAFC] relative overflow-hidden items-center justify-center p-12 min-h-full ${
                        isFormLeft 
                            ? 'order-2 border-l border-slate-200' 
                            : 'order-2 lg:order-1 border-r border-slate-200'
                    }`}>
                        {/* Official Bagoo Floating Badge (Top Left) */}
                        <div className="absolute top-8 left-8 z-10 flex items-center gap-2.5 bg-white border border-slate-300 px-3.5 py-2 rounded-xs shadow-xs font-mono text-xs">
                            <BagooLogo className="w-5 h-5" rounded="rounded-xs" />
                            <span className="font-bold text-slate-900">BagooPH</span>
                            <span className="text-slate-300">/</span>
                            <span className="text-[11px] text-slate-600 font-semibold uppercase tracking-wider">
                                {imageBadge || 'Official Service'}
                            </span>
                        </div>

                        {/* Alternate Portal Switcher on Desktop (Top Right) */}
                        {alternatePortal && (
                            <div className="absolute top-8 right-8 z-10">
                                <a
                                    href={alternatePortal.href}
                                    className="flex items-center gap-2 bg-white hover:bg-slate-50 border border-slate-300 hover:border-slate-400 px-3.5 py-2 rounded-xs shadow-xs text-xs font-mono transition group"
                                >
                                    {alternatePortal.subtext && (
                                        <span className="text-slate-500 text-[11px] hidden xl:inline">{alternatePortal.subtext}</span>
                                    )}
                                    <span className="font-bold text-slate-900 group-hover:text-[#E00D42] transition-colors">
                                        {alternatePortal.buttonText}
                                    </span>
                                </a>
                            </div>
                        )}

                        {/* Centered Artwork Showcase */}
                        <div className="flex flex-col items-center justify-center max-w-lg text-center">
                            <img 
                                src={imageSrc} 
                                alt={imageAlt || 'BagooPH Auth Visual'} 
                                className="w-full max-h-[55vh] object-contain rounded-xs border border-slate-300 shadow-xs select-none"
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

                {/* Ecosystem Footer (Revealed upon scroll) */}
                {showFooter && <AuthEcosystemFooter />}
            </div>
        );
    }

    // Centered Fallback (for admin login, hub login, email verification, password reset, etc.)
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
        <div className="min-h-screen flex flex-col bg-[#F8FAFC] text-[#0F172A] font-sans selection:bg-[#E00D42] selection:text-white">
            <div className="min-h-[100vh] min-h-[100dvh] flex flex-col justify-between p-4 sm:p-6 lg:p-8 flex-1">
                <header className="w-full max-w-6xl mx-auto flex items-center justify-between font-mono text-xs py-2">
                    <Link href="/" className="group flex items-center gap-2.5">
                        <BagooLogo className="w-8 h-8 group-hover:scale-105 transition-transform duration-200" rounded="rounded-xs" />
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
                        <div className="bg-white rounded-xs border border-slate-300 shadow-xs p-6 sm:p-8">
                            {(title || headerBadge) && (
                                <div className="mb-6 pb-4 border-b border-slate-100">
                                    {headerBadge && (
                                        <span className="inline-block px-2.5 py-1 mb-2 rounded-xs bg-slate-100 text-slate-700 font-mono text-[10px] font-bold uppercase tracking-wider">
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

                <div className="w-full max-w-6xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-2 font-mono text-[10px] text-slate-400 py-3 border-t border-slate-200">
                    <span>BAGOO SECURE GATEWAY // ENCRYPTED SESSION</span>
                    <span>METRO MANILA COD LOGISTICS // 10% FLAT PLATFORM STANDARD</span>
                </div>
            </div>

            {/* Ecosystem Footer (Revealed upon scroll) */}
            {showFooter && <AuthEcosystemFooter />}
        </div>
    );
}
