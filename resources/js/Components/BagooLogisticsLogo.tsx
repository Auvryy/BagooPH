import React from 'react';

interface Props {
    className?: string;
    rounded?: string;
}

export default function BagooLogisticsLogo({ className = 'w-10 h-10', rounded = 'rounded-xs' }: Props) {
    return (
        <div
            className={`relative overflow-hidden inline-flex items-center justify-center shrink-0 ${rounded} ${className}`}
            aria-label="BagooPH Logistics"
        >
            <svg
                viewBox="0 0 128 128"
                fill="none"
                xmlns="http://www.w3.org/2000/svg"
                className="w-full h-full"
            >
                <rect width="128" height="128" fill="#E00D42" />
                <path d="M17.4607 43.7978H30.0674" stroke="white" strokeWidth="4" strokeLinecap="round" />
                <path d="M6 60.9888H30.0674" stroke="white" strokeWidth="4" strokeLinecap="round" />
                <path d="M12.8764 78.1798H30.0674" stroke="white" strokeWidth="4" strokeLinecap="round" />
                <path d="M24.3371 95.3708H30.0674" stroke="white" strokeWidth="4" strokeLinecap="round" />
                <circle cx="56.5" cy="22.5" r="5" fill="#C50032" stroke="white" strokeWidth="4" />
                <circle cx="93.5" cy="22.5" r="5" fill="#C50032" stroke="white" strokeWidth="4" />
                <path d="M66.5 22.5H72" stroke="white" strokeWidth="3.5" strokeLinecap="round" />
                <path d="M78 22.5H83.5" stroke="white" strokeWidth="3.5" strokeLinecap="round" />
                <rect x="41.5" y="37" width="66.5" height="65" rx="4" fill="#C50032" stroke="white" strokeWidth="5.5" />
                <path d="M41.5 55H108" stroke="white" strokeWidth="4.5" />
                <rect x="67.5" y="37" width="15" height="22" rx="2" fill="white" />
                <rect x="72.5" y="42.5" width="5" height="7.5" rx="1" fill="#E00D42" />
                <path d="M54 67.5V88" stroke="white" strokeWidth="4.5" strokeLinecap="round" />
                <path d="M63 67.5V88" stroke="white" strokeWidth="3" strokeLinecap="round" />
                <path d="M71.5 67.5V88" stroke="white" strokeWidth="4.5" strokeLinecap="round" />
                <path d="M80.5 77.75H95.5M95.5 77.75L89 71.25M95.5 77.75L89 84.25" stroke="white" strokeWidth="4.5" strokeLinecap="round" strokeLinejoin="round" />
            </svg>
        </div>
    );
}
