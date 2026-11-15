import { ButtonHTMLAttributes } from 'react';

export default function PrimaryButton({
    className = '',
    disabled,
    children,
    ...props
}: ButtonHTMLAttributes<HTMLButtonElement>) {
    return (
        <button
            {...props}
            className={
                `inline-flex items-center rounded border border-[#C20836] bg-[#E00D42] px-4 py-2 text-xs font-semibold uppercase tracking-wider text-white transition duration-150 ease-in-out hover:bg-[#C20836] focus:bg-[#C20836] focus:outline-none focus:ring-2 focus:ring-[#E00D42] focus:ring-offset-2 active:bg-[#A8062E] cursor-pointer ${
                    disabled && 'opacity-25 cursor-not-allowed'
                } ` + className
            }
            disabled={disabled}
        >
            {children}
        </button>
    );
}
