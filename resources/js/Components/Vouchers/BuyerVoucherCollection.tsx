import { Link } from '@inertiajs/react';
import { Bookmark, CheckCircle2, Clock3, Tag } from 'lucide-react';
import VoucherOfferList from '@/Components/VoucherOfferList';
import { VoucherOffer } from '@/hooks/usePurchaseQuote';
import { PaginatedData } from '@/types';
import VoucherPagination from './VoucherPagination';

export interface BuyerVoucherCollectionData {
    tab: 'available' | 'saved' | 'used' | 'expired';
    counts: Record<'available' | 'saved' | 'used' | 'expired', number>;
    offers: PaginatedData<VoucherOffer>;
}

export default function BuyerVoucherCollection({ collection, allowSave = true }: { collection: BuyerVoucherCollectionData; allowSave?: boolean }) {
    const tabs = [
        { id: 'available', title: 'Available', icon: Tag },
        { id: 'saved', title: 'Saved', icon: Bookmark },
        { id: 'used', title: 'Used', icon: CheckCircle2 },
        { id: 'expired', title: 'Expired', icon: Clock3 },
    ] as const;
    return <>
        <div className="border-b border-slate-200 pb-4">
            <h3 className="text-base font-bold text-slate-900">My Vouchers & Promos</h3>
            <p className="mt-2 text-xs leading-5 text-slate-600">Saved vouchers are bookmarks. Availability is checked again at checkout; no stock, use or discount is reserved.</p>
        </div>
        <nav aria-label="Voucher collections" className="flex flex-wrap gap-2">
            {tabs.map(({ id, title, icon: Icon }) => <Link key={id} preserveScroll
                href={route('buyer.profile', { tab: 'vouchers', voucher_tab: id })} aria-current={collection.tab === id ? 'page' : undefined}
                aria-label={`${title} vouchers, ${collection.counts[id]}`}
                className={`inline-flex min-h-11 items-center gap-2 rounded-full border px-3.5 py-2 text-xs font-bold transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42] ${collection.tab === id ? 'border-[#E00D42] bg-[#E00D42] text-white shadow-sm' : 'border-slate-300 bg-white text-slate-700 hover:border-rose-200 hover:bg-rose-50'}`}>
                <Icon className="h-4 w-4" aria-hidden="true" />
                <span>{title}</span>
                <span className={`rounded-full px-2 py-0.5 text-[10px] tabular-nums ${collection.tab === id ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-600'}`}>{collection.counts[id]}</span>
            </Link>)}
        </nav>
        <VoucherOfferList offers={collection.offers.data} allowSave={allowSave} emptyTitle={`No ${collection.tab} vouchers`}
            emptyDescription={collection.tab === 'saved' ? 'Save a shop or Bagoo offer to find its terms here.'
                : collection.tab === 'used' ? 'Offers you actually use at checkout will appear here.'
                : collection.tab === 'expired' ? 'Expired vouchers you saved or used will appear here.' : 'Shop and Bagoo offers will appear here when available.'} />
        <VoucherPagination page={collection.offers} />
    </>;
}
