import React from 'react';
import BagooLogo from './BagooLogo';

interface Props {
    className?: string;
    showText?: boolean;
}

export default function ApplicationLogo({ className = 'w-10 h-10', showText = true }: Props) {
    return (
        <div className="flex items-center gap-3">
            <BagooLogo className={className} />
            {showText && (
                <span className="text-2xl font-black tracking-tight text-slate-900 font-sans">
                    Bagoo<span className="text-[#E00D42]">PH</span>
                </span>
            )}
        </div>
    );
}
