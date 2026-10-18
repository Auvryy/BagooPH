import { Head, usePage } from '@inertiajs/react';
import { Store } from 'lucide-react';
import BuyerLayout from '@/Layouts/BuyerLayout';
import PortalLink from '@/Components/PortalLink';
import ShopFollowButton from '@/Components/ShopFollowButton';
import useResolvedPage from '@/hooks/useResolvedPage';
import { PageProps, PaginatedData } from '@/types';

interface Entry {
    id: number;
    shop_id: number;
    shop: { id: number; name: string; slug: string; logo?: string; description?: string } | null;
}

export default function FollowedShops({ entries }: { entries: PaginatedData<Entry> }) {
    useResolvedPage(entries.current_page);
    const { flash } = usePage<PageProps>().props;
    return <BuyerLayout>
        <Head title="Followed shops — BagooPH" />
        <main className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-300 pb-5">
                <div><h1 className="text-2xl font-bold text-slate-900">Followed shops</h1><p className="mt-1 text-sm text-slate-500">Return to the shops you want to keep up with.</p></div>
                <PortalLink href={route('buyer.search')} className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700">Continue shopping</PortalLink>
            </div>
            {flash.success && <p role="status" className="rounded-lg border border-emerald-300 bg-emerald-50 p-3 text-sm text-emerald-800">{flash.success}</p>}
            {entries.data.length === 0 ? <div className="flex min-h-64 flex-col items-center justify-center rounded-2xl border border-slate-300 bg-white p-8 text-center">
                <Store className="mb-3 h-8 w-8 text-[#E00D42]" /><h2 className="font-bold text-slate-900">No followed shops yet</h2><p className="mt-2 text-sm text-slate-500">Choose Follow shop on a storefront to keep it here.</p>
            </div> : <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {entries.data.map(entry => <article key={entry.id} className="flex flex-col justify-between gap-4 rounded-xl border border-slate-300 bg-white p-5">
                    {entry.shop ? <PortalLink href={route('shop.show', entry.shop.slug)} className="flex items-start gap-3 hover:text-[#E00D42]">
                        {entry.shop.logo ? <img src={entry.shop.logo} alt="" loading="lazy" className="h-12 w-12 rounded-lg object-cover" /> : <Store className="h-10 w-10 shrink-0 text-slate-400" />}
                        <div className="min-w-0"><h2 className="break-words font-bold">{entry.shop.name}</h2><p className="mt-1 line-clamp-2 text-sm text-slate-500">{entry.shop.description || 'Visit this shop’s catalogue.'}</p></div>
                    </PortalLink> : <div><h2 className="font-bold text-slate-800">Shop currently unavailable</h2><p className="mt-1 text-sm text-slate-500">You can keep following or remove it from your list.</p></div>}
                    <ShopFollowButton shopId={entry.shop_id} following />
                </article>)}
            </div>}
            <nav aria-label="Followed shop pages" className="flex items-center justify-center gap-4 text-sm">
                {entries.prev_page_url && <PortalLink href={entries.prev_page_url} className="rounded-lg border border-slate-300 bg-white px-3 py-2">Previous</PortalLink>}
                <span>Page {entries.current_page} of {entries.last_page}</span>
                {entries.next_page_url && <PortalLink href={entries.next_page_url} className="rounded-lg border border-slate-300 bg-white px-3 py-2">Next</PortalLink>}
            </nav>
        </main>
    </BuyerLayout>;
}
