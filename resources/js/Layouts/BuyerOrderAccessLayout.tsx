import { PropsWithChildren } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import BagooLogo from '@/Components/BagooLogo';
import BuyerLayout from '@/Layouts/BuyerLayout';
import { PageProps } from '@/types';

export default function BuyerOrderAccessLayout({ canUsePortal, children }: PropsWithChildren<{ canUsePortal: boolean }>) {
    const { flash, auth } = usePage<PageProps>().props;
    if (canUsePortal) return <BuyerLayout>{children}</BuyerLayout>;

    return (
        <div className="min-h-screen bg-[#F8F6F2] font-sans text-slate-900">
            <header className="border-b border-slate-300 bg-white">
                <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-4 py-5 sm:px-6">
                    <Link href={route('buyer.index')} aria-label="Bagoo marketplace"><BagooLogo className="h-8 w-auto" /></Link>
                    <nav aria-label="Available account actions" className="flex flex-wrap gap-4 text-sm font-semibold">
                        <Link href={route('buyer.index')} className="text-[#C20836]">Browse products</Link>
                        {auth.user?.role === 'buyer' && <Link href={route('kyc.pending')}>Account status</Link>}
                        <button onClick={() => router.post(route('logout'))}>Sign out</button>
                    </nav>
                </div>
            </header>
            <main className="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6">
                <p className="rounded-xl border border-slate-300 bg-white p-4 text-sm">This view provides access to recorded orders. Receipt confirmation is available only to the buyer of a delivered order.</p>
                {flash?.success && <p role="status" className="rounded-xl border border-emerald-300 bg-emerald-50 p-3 text-emerald-800">{flash.success}</p>}
                {flash?.error && <p role="alert" className="rounded-xl border border-rose-300 bg-rose-50 p-3 text-rose-800">{flash.error}</p>}
                {children}
            </main>
        </div>
    );
}
