import type { ButtonHTMLAttributes } from 'react';

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger';
type Size = 'sm' | 'md' | 'lg';

const variants: Record<Variant, string> = {
    primary: 'bg-brand-500 text-white hover:bg-brand-600 disabled:bg-line-300 disabled:text-ink-600',
    secondary: 'border border-line-300 bg-white text-ink-900 hover:bg-surface-100 disabled:text-ink-500',
    ghost: 'text-ink-700 hover:bg-surface-100 hover:text-ink-900 disabled:text-ink-400',
    danger: 'border border-brand-700 bg-white text-brand-700 hover:bg-brand-50 disabled:opacity-50',
};

const sizes: Record<Size, string> = {
    sm: 'h-8 px-3 text-[13px]',
    md: 'h-10 px-4 text-sm',
    lg: 'h-12 px-5 text-base',
};

export const buttonClasses = (variant: Variant = 'secondary', size: Size = 'md', extra = '') =>
    `inline-flex items-center justify-center gap-2 rounded-control font-semibold transition-colors duration-150 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 disabled:cursor-not-allowed ${variants[variant]} ${sizes[size]} ${extra}`.trim();

type Props = ButtonHTMLAttributes<HTMLButtonElement> & { variant?: Variant; size?: Size };

export default function Button({ variant = 'secondary', size = 'md', className = '', type = 'button', ...props }: Props) {
    return <button type={type} className={buttonClasses(variant, size, className)} {...props} />;
}
