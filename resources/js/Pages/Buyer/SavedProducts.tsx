import { Head, Link, usePage } from '@inertiajs/react';
import { Heart, ShoppingBag } from 'lucide-react';
import BuyerLayout from '@/Layouts/BuyerLayout';
import ProductCard from '@/Components/ProductCard';
import SavedProductButton from '@/Components/SavedProductButton';
import { PageProps, PaginatedData, Product } from '@/types';

interface Entry {
    id: number;
    product_id: number;
    product: Product | null;
    available: boolean;
    unavailable_reason: string;
}

export default function SavedProducts({ entries }: { entries: PaginatedData<Entry> }) {
    const { flash } = usePage<PageProps>().props;
    return <BuyerLayout>
        <Head title="Saved products — BagooPH" />
        <main className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-300 pb-5">
                <div><h1 className="text-2xl font-bold text-slate-900">Saved products</h1><p className="mt-1 text-sm text-slate-500">Keep products for later. Saving does not reserve stock.</p></div>
                <Link href={route('buyer.search')} className="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700"><ShoppingBag className="h-4 w-4" />Continue shopping</Link>
            </div>
            {flash.success && <p role="status" className="rounded-lg border border-emerald-300 bg-emerald-50 p-3 text-sm text-emerald-800">{flash.success}</p>}
            {entries.data.length === 0 ? <div className="rounded-2xl border border-slate-300 bg-white p-10 text-center">
                <Heart className="mx-auto mb-3 h-8 w-8 text-[#E00D42]" /><h2 className="font-bold text-slate-900">No saved products yet</h2><p className="mt-2 text-sm text-slate-500">Use Save product when browsing the catalogue.</p>
            </div> : <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                {entries.data.map(entry => entry.product ? <ProductCard key={entry.id} product={entry.product} href={route('buyer.products.show', entry.product.slug)} saveControl={<SavedProductButton productId={entry.product_id} />} /> :
                    <article key={entry.id} className="flex flex-col justify-between rounded-lg border border-slate-300 bg-white p-4">
                        <div className="py-6 text-center"><ShoppingBag className="mx-auto mb-3 h-8 w-8 text-slate-400" /><h2 className="text-sm font-bold text-slate-800">Saved product unavailable</h2><p className="mt-2 text-xs text-slate-500">{entry.unavailable_reason}</p><button type="button" disabled className="mt-3 rounded-lg border border-slate-300 px-3 py-2 text-xs text-slate-400">Unavailable for purchase</button></div>
                        <SavedProductButton productId={entry.product_id} />
                    </article>)}
            </div>}
            <nav aria-label="Saved product pages" className="flex items-center justify-center gap-4 text-sm">
                {entries.prev_page_url && <Link href={entries.prev_page_url} className="rounded-lg border border-slate-300 bg-white px-3 py-2">Previous</Link>}
                <span>Page {entries.current_page} of {entries.last_page}</span>
                {entries.next_page_url && <Link href={entries.next_page_url} className="rounded-lg border border-slate-300 bg-white px-3 py-2">Next</Link>}
            </nav>
        </main>
    </BuyerLayout>;
}
