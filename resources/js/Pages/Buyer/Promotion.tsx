import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, CalendarDays, ShoppingBag } from 'lucide-react';
import BuyerLayout from '@/Layouts/BuyerLayout';
import ProductCard from '@/Components/ProductCard';
import AdvertisementOfferTerms from '@/Components/AdvertisementOfferTerms';
import { Category, PageProps, Product } from '@/types';
import { Destination, OfferTerms, dateLabel } from '@/types/advertisements';

interface Props {
    promotion: { title: string; summary: string | null; available: boolean; state: string; image: string | null;
        alt_text: string; width: number; height: number; starts_at: string; ends_at: string; offer: OfferTerms | null; target: Destination | null };
    products: Product[]; categories: Category[];
}
export default function Promotion({ promotion, products, categories }: Props) {
    const user = usePage<PageProps>().props.auth.user;
    return <BuyerLayout categories={categories}><Head title={promotion.available ? promotion.title : 'Promotion unavailable'} />
        <div className="mx-auto max-w-[1440px] space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <Link href={route('marketplace')} className="inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-[#E00D42]"><ArrowLeft className="h-4 w-4" />Continue shopping</Link>
            {!promotion.available ? <section className="flex min-h-80 flex-col items-center justify-center rounded-lg border border-slate-300 bg-white p-8 text-center">
                <ShoppingBag className="mb-4 h-9 w-9 text-slate-400" /><h1 className="text-2xl font-bold text-slate-900">This promotion is no longer available</h1><p className="mt-3 max-w-lg text-sm leading-relaxed text-slate-500">It may have ended or its products or offer may have changed. You can still browse the marketplace for current products and offers.</p><Link href={route('marketplace')} className="mt-6 rounded-lg bg-[#E00D42] px-5 py-3 text-sm font-bold text-white">Browse products</Link>
            </section> : <>
                <header className="space-y-3"><h1 className="text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">{promotion.title}</h1><p className="flex items-center gap-2 text-xs text-slate-500"><CalendarDays className="h-4 w-4 shrink-0" />{dateLabel(promotion.starts_at)} – {dateLabel(promotion.ends_at)} (Manila)</p>{promotion.summary && <p className="max-w-3xl whitespace-pre-line text-sm leading-relaxed text-slate-600">{promotion.summary}</p>}</header>
                {promotion.image && <img src={promotion.image} alt={promotion.alt_text} width={promotion.width} height={promotion.height} className="h-auto w-full max-w-3xl" />}
                {promotion.offer && <div className="max-w-3xl space-y-4"><AdvertisementOfferTerms offer={promotion.offer} /><Link href={route('buyer.profile', { tab: 'vouchers' })} className="inline-flex rounded-lg bg-[#E00D42] px-5 py-3 text-sm font-bold text-white">{user ? 'View my vouchers' : 'Sign in to save a voucher'}</Link></div>}
                {promotion.target?.url && <Link href={promotion.target.url} className="inline-block text-sm font-bold text-[#E00D42] underline">Explore {promotion.target.label}</Link>}
                <section className="space-y-4"><h2 className="text-lg font-bold text-slate-900">Products in this promotion</h2>{products.length ? <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">{products.map((product) => <ProductCard key={product.id} product={product} href={route('products.show', product.slug)} showDeliveryBadge={false} />)}</div> : <div className="flex min-h-48 items-center justify-center rounded-lg border border-slate-300 bg-white p-6 text-center text-sm text-slate-500">No products are currently available in this collection. Continue shopping for more choices.</div>}</section>
            </>}
        </div>
    </BuyerLayout>;
}
