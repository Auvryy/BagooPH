import React from 'react';

interface SkeletonProps {
    className?: string;
    rounded?: 'sm' | 'md' | 'lg' | 'xl' | 'full';
}

/**
 * Base atomic Skeleton shimmer block
 */
export function Skeleton({ className = 'h-4 w-full', rounded = 'lg' }: SkeletonProps) {
    const roundedClass = {
        sm: 'rounded-sm',
        md: 'rounded-md',
        lg: 'rounded-lg',
        xl: 'rounded-xl',
        full: 'rounded-full',
    }[rounded];

    return (
        <div 
            className={`animate-pulse bg-gradient-to-r from-slate-200 via-slate-100 to-slate-200 dark:from-slate-800 dark:via-slate-700/60 dark:to-slate-800 ${roundedClass} ${className}`}
        />
    );
}

/**
 * Product Card Skeleton (for Buyer Marketplace, Catalog, and Storefronts)
 */
export function ProductCardSkeleton() {
    return (
        <div className="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 overflow-hidden shadow-xs flex flex-col p-3 space-y-3">
            {/* Image Placeholder */}
            <Skeleton className="w-full aspect-square" rounded="lg" />
            
            {/* Department Tag & Price */}
            <div className="flex items-center justify-between">
                <Skeleton className="h-3.5 w-16" rounded="sm" />
                <Skeleton className="h-4 w-20" rounded="sm" />
            </div>

            {/* Title */}
            <div className="space-y-1.5">
                <Skeleton className="h-4 w-full" rounded="sm" />
                <Skeleton className="h-4 w-3/4" rounded="sm" />
            </div>

            {/* Shop & Button */}
            <div className="pt-2 border-t border-slate-100 dark:border-slate-800/80 flex items-center justify-between">
                <Skeleton className="h-3 w-24" rounded="sm" />
                <Skeleton className="h-7 w-20" rounded="lg" />
            </div>
        </div>
    );
}

/**
 * Bento Stats Metric Skeleton (for Seller & Admin Cockpits)
 */
export function StatsCardSkeleton() {
    return (
        <div className="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 space-y-3 shadow-xs">
            <div className="flex items-center justify-between">
                <Skeleton className="h-3.5 w-24" rounded="sm" />
                <Skeleton className="h-8 w-8" rounded="lg" />
            </div>
            <Skeleton className="h-7 w-32" rounded="md" />
            <Skeleton className="h-3 w-40" rounded="sm" />
        </div>
    );
}

/**
 * Table Rows Skeleton (for Orders, Inventory, Courier Deliveries, and Admin KYC)
 */
export function TableSkeleton({ rows = 5, cols = 5 }: { rows?: number; cols?: number }) {
    return (
        <div className="w-full rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 overflow-hidden">
            {/* Table Header */}
            <div className="bg-slate-50 dark:bg-slate-800/50 p-4 border-b border-slate-200 dark:border-slate-800 grid gap-4" style={{ gridTemplateColumns: `repeat(${cols}, 1fr)` }}>
                {Array.from({ length: cols }).map((_, i) => (
                    <Skeleton key={i} className="h-3.5 w-3/4" rounded="sm" />
                ))}
            </div>

            {/* Table Body */}
            <div className="divide-y divide-slate-100 dark:divide-slate-800/60 p-2">
                {Array.from({ length: rows }).map((_, r) => (
                    <div key={r} className="p-3 grid gap-4 items-center" style={{ gridTemplateColumns: `repeat(${cols}, 1fr)` }}>
                        {Array.from({ length: cols }).map((_, c) => (
                            <Skeleton key={c} className={`h-4 ${c === 0 ? 'w-4/5' : c === cols - 1 ? 'w-1/2' : 'w-full'}`} rounded="sm" />
                        ))}
                    </div>
                ))}
            </div>
        </div>
    );
}

/**
 * Order Tracking Card Skeleton (for Buyer and Courier views)
 */
export function OrderCardSkeleton() {
    return (
        <div className="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 space-y-4 shadow-xs">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                <Skeleton className="h-4 w-32" rounded="sm" />
                <Skeleton className="h-6 w-24" rounded="full" />
            </div>
            <div className="flex items-center gap-4">
                <Skeleton className="w-16 h-16 shrink-0" rounded="lg" />
                <div className="flex-1 space-y-2">
                    <Skeleton className="h-4 w-3/4" rounded="sm" />
                    <Skeleton className="h-3.5 w-1/3" rounded="sm" />
                </div>
                <Skeleton className="h-6 w-20" rounded="sm" />
            </div>
            <div className="pt-3 border-t border-slate-100 dark:border-slate-800 flex justify-between items-center">
                <Skeleton className="h-3.5 w-40" rounded="sm" />
                <Skeleton className="h-8 w-28" rounded="lg" />
            </div>
        </div>
    );
}

export default Skeleton;
