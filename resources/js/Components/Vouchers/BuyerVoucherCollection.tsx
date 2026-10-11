import { Link } from '@inertiajs/react';
import VoucherOfferList from '@/Components/VoucherOfferList';
import { VoucherOffer } from '@/hooks/usePurchaseQuote';
import { PaginatedData } from '@/types';
import VoucherPagination from './VoucherPagination';

export interface BuyerVoucherCollectionData {
    tab: 'available' | 'saved' | 'used' | 'expired';
    counts: Record<'available' | 'saved' | 'used' | 'expired', number>;
    offers: PaginatedData<VoucherOffer>;
}

export default function BuyerVoucherCollection({ collection }: { collection: BuyerVoucherCollectionData }) {
    const titles = { available: 'Available', saved: 'Saved', used: 'Used', expired: 'Expired' };
    return <>
        <div className="border-b border-slate-200 pb-4">
            <h3 className="text-base font-bold text-slate-900">My Vouchers & Promos</h3>
            <p className="mt-2 text-xs leading-5 text-slate-600">Saved vouchers are bookmarks. Availability is checked again at checkout; no stock, use or discount is reserved.</p>
        </div>
        <nav aria-label="Voucher collections" className="flex flex-wrap gap-2">
            {Object.entries(titles).map(([tab, title]) => <Link key={tab} preserveScroll
                href={route('buyer.profile', { tab: 'vouchers', voucher_tab: tab })} aria-current={collection.tab === tab ? 'page' : undefined}
                className={`min-h-11 rounded-xl border px-3 py-2 text-xs font-bold focus-visible:ring-2 focus-visible:ring-[#E00D42] ${collection.tab === tab ? 'border-[#E00D42] bg-[#E00D42] text-white' : 'border-slate-300 text-slate-700 hover:bg-slate-50'}`}>
                {title} ({collection.counts[tab as keyof typeof titles]})
            </Link>)}
        </nav>
        <VoucherOfferList offers={collection.offers.data} allowSave emptyTitle={`No ${collection.tab} vouchers`}
            emptyDescription={collection.tab === 'saved' ? 'Save a shop or Bagoo offer to find its terms here.'
                : collection.tab === 'used' ? 'Offers you actually use at checkout will appear here.'
                : collection.tab === 'expired' ? 'Expired vouchers you saved or used will appear here.' : 'Shop and Bagoo offers will appear here when available.'} />
        <VoucherPagination page={collection.offers} />
    </>;
}
