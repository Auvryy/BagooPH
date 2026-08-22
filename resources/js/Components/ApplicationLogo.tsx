import { SVGAttributes } from 'react';

export default function ApplicationLogo(props: SVGAttributes<SVGElement>) {
    return (
        <div className="flex items-center gap-2">
            <div className="w-10 h-10 rounded-lg bg-[#E00D42] flex items-center justify-center text-white font-bold shadow-xs">
                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                </svg>
            </div>
            <span className="text-2xl font-black tracking-tight text-slate-900">
                Bagoo<span className="text-[#E00D42]">PH</span>
            </span>
        </div>
    );
}
