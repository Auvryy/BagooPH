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
                <path d="M16 44H27" stroke="white" strokeWidth="4" strokeLinecap="round" />
                <path d="M6 59H27" stroke="white" strokeWidth="4" strokeLinecap="round" />
                <path d="M12 74H27" stroke="white" strokeWidth="4" strokeLinecap="round" />
                <rect x="33" y="36" width="51" height="46" rx="4" fill="#C50032" stroke="white" strokeWidth="5.5" />
                <path d="M44 53H73" stroke="white" strokeWidth="4.5" strokeLinecap="round" />
                <path d="M44 64H64" stroke="white" strokeWidth="4.5" strokeLinecap="round" />
                <path
                    d="M84 48H98.5C101 48 103.3 49.4 104.5 51.6L112.2 65.5C112.7 66.5 113 67.6 113 68.7V78C113 80.2 111.2 82 109 82H84V48Z"
                    fill="#C50032"
                    stroke="white"
                    strokeWidth="5.5"
                    strokeLinejoin="round"
                />
                <path d="M91 55.5H98.5L104.5 65.5H91V55.5Z" fill="white" />
                <circle cx="52" cy="87" r="10.5" fill="#E00D42" stroke="white" strokeWidth="5.5" />
                <circle cx="52" cy="87" r="2.8" fill="white" />
                <circle cx="96.5" cy="87" r="10.5" fill="#E00D42" stroke="white" strokeWidth="5.5" />
                <circle cx="96.5" cy="87" r="2.8" fill="white" />
            </svg>
        </div>
    );
}
