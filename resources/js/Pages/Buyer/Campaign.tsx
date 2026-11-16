import { ReactNode, useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Check, Clock, Store } from 'lucide-react';
import BuyerLayout from '@/Layouts/BuyerLayout';
import type { PageProps } from '@/types';

interface CampaignSummary { id: number; slug: string; title: string; badge: string; banner_url: string | null; starts_at: string; ends_at: string; status: 'upcoming' | 'live' | 'ended' }
interface EventVoucher {
    id: number; code: string; name: string; discount_type: 'fixed' | 'percent' | 'free_shipping'; discount_value: number; min_spend: number; max_discount: number;
    shop_name: string | null; issuer: 'platform' | 'shop'; expires_at: string; claim_limit: number | null; claimed_count: number; sold_out: boolean; claimed: boolean;
}
interface EventProduct {
    sale_id: number; id: number; name: string; slug: string; image: string | null; regular_price: number; price: number; discount_pct: number; live: boolean;
    stock: number; rating: number | null; sales_count: number; shop: { id: number; name: string; slug: string } | null; category: { id: number; name: string; slug: string } | null;
}
interface Props {
    campaign: CampaignSummary; vouchers: EventVoucher[]; products: EventProduct[]; categories: { id: number; name: string; slug: string }[];
    allCampaigns: { slug: string; title: string; badge: string; status: string }[]; serverNow: string;
}

const peso = (value: number) => `₱${value.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const pesoShort = (value: number) => `₱${value.toLocaleString('en-PH', { maximumFractionDigits: 2 })}`;

export function voucherHeadline(voucher: Pick<EventVoucher, 'discount_type' | 'discount_value'>): string {
    if (voucher.discount_type === 'percent') return `${voucher.discount_value}% off`;
    if (voucher.discount_type === 'free_shipping') return `${pesoShort(voucher.discount_value)} off shipping`;
    return `${pesoShort(voucher.discount_value)} off`;
}

/** Milliseconds left until `target`, measured on the server's clock so a wrong device clock cannot shift it. */
export function useServerCountdown(target: string, serverNow: string): number {
    const offset = useRef(new Date(serverNow).getTime() - Date.now());
    const remaining = () => Math.max(0, new Date(target).getTime() - (Date.now() + offset.current));
    const [left, setLeft] = useState(remaining);
    useEffect(() => {
        offset.current = new Date(serverNow).getTime() - Date.now();
        setLeft(remaining());
        const timer = window.setInterval(() => setLeft(remaining()), 1000);
        return () => window.clearInterval(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [target, serverNow]);
    return left;
}

export function countdownParts(ms: number) {
    const total = Math.floor(ms / 1000);
    return { days: Math.floor(total / 86400), hours: Math.floor((total % 86400) / 3600), minutes: Math.floor((total % 3600) / 60), seconds: total % 60 };
}

function Countdown({ campaign, serverNow }: { campaign: CampaignSummary; serverNow: string }) {
    const target = campaign.status === 'upcoming' ? campaign.starts_at : campaign.ends_at;
    const left = useServerCountdown(target, serverNow);
    const reloaded = useRef(false);
    useEffect(() => {
        if (left === 0 && campaign.status !== 'ended' && !reloaded.current) {
            reloaded.current = true;
            router.reload({ preserveScroll: true });
        }
    }, [left, campaign.status]);
    if (campaign.status === 'ended') return <p className="text-sm font-medium text-ink-600">This event has ended</p>;
    const { days, hours, minutes, seconds } = countdownParts(left);
    const unit = (value: number, label: string) => (
        <span className="flex flex-col items-center"><span className="min-w-10 rounded-control bg-white px-2 py-1 text-center text-lg font-bold tabular-nums text-ink-900">{String(value).padStart(2, '0')}</span><span className="mt-1 text-xs text-ink-600">{label}</span></span>
    );
    return (
        <div className="flex items-center gap-3" role="timer" aria-label={`${campaign.status === 'upcoming' ? 'Starts' : 'Ends'} in ${days} days ${hours} hours ${minutes} minutes`}>
            <span className="flex items-center gap-1.5 text-sm font-medium text-ink-700"><Clock className="h-4 w-4" aria-hidden="true" />{campaign.status === 'upcoming' ? 'Starts in' : 'Ends in'}</span>
            <span className="flex gap-1.5" aria-hidden="true">{days > 0 && unit(days, 'days')}{unit(hours, 'hrs')}{unit(minutes, 'min')}{unit(seconds, 'sec')}</span>
        </div>
    );
}

function VoucherCard({ voucher, live }: { voucher: EventVoucher; live: boolean }) {
    const [busy, setBusy] = useState(false);
    const claim = () => {
        if (busy || voucher.claimed) return;
        setBusy(true);
        router.post(`/campaign/vouchers/${voucher.id}/claim`, {}, { preserveScroll: true, only: ['vouchers', 'flash'], onFinish: () => setBusy(false) });
    };
    const limited = voucher.claim_limit !== null;
    const percent = limited ? Math.min(100, Math.round((voucher.claimed_count / Math.max(1, voucher.claim_limit!)) * 100)) : 0;
    const terms = [voucher.min_spend > 0 ? `Min. spend ${peso(voucher.min_spend)}` : 'No minimum spend',
        voucher.discount_type !== 'fixed' && voucher.max_discount > 0 ? `up to ${peso(voucher.max_discount)}` : null].filter(Boolean).join(' · ');
    return (
        <li className="flex flex-col justify-between gap-3 rounded-card border border-line-200 bg-white p-4">
            <div>
                <p className="text-xs font-medium text-ink-600">{voucher.issuer === 'platform' ? 'Bagoo voucher' : voucher.shop_name}</p>
                <p className="mt-1 text-xl font-bold text-ink-900">{voucherHeadline(voucher)}</p>
                <p className="mt-0.5 text-sm text-ink-700">{terms}</p>
                <p className="mt-1 font-mono text-xs text-ink-500">{voucher.code}</p>
            </div>
            {limited && (
                <div>
                    <div className="h-1.5 overflow-hidden rounded-full bg-surface-100"><div className="h-full rounded-full bg-brand-500" style={{ width: `${percent}%` }} /></div>
                    <p className="mt-1 text-xs text-ink-600">{voucher.sold_out ? 'All claimed' : `${voucher.claim_limit! - voucher.claimed_count} of ${voucher.claim_limit} left`}</p>
                </div>
            )}
            {voucher.claimed ? (
                <p className="flex h-10 items-center justify-center gap-1.5 rounded-control bg-success-soft text-sm font-semibold text-success"><Check className="h-4 w-4" aria-hidden="true" />Claimed</p>
            ) : (
                <button type="button" onClick={claim} disabled={busy || voucher.sold_out || !live}
                    className="h-10 rounded-control border border-brand-500 bg-white text-sm font-semibold text-brand-600 hover:bg-brand-50 disabled:border-line-300 disabled:text-ink-500 disabled:hover:bg-white">
                    {busy ? 'Claiming…' : voucher.sold_out ? 'All claimed' : live ? 'Claim' : 'Opens when the event starts'}
                </button>
            )}
        </li>
    );
}

function ProductTile({ product, live }: { product: EventProduct; live: boolean }) {
    return (
        <li>
            <Link href={`/product/${product.slug}`} className="group flex h-full flex-col overflow-hidden rounded-card border border-line-200 bg-white hover:border-line-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500">
                <div className="relative aspect-square bg-surface-100">
                    {product.image && <img src={product.image} alt="" loading="lazy" className="h-full w-full object-cover" />}
                    <span className="absolute left-2 top-2 rounded bg-brand-500 px-1.5 py-0.5 text-xs font-semibold text-white">-{product.discount_pct}%</span>
                </div>
                <div className="flex flex-1 flex-col gap-1 p-3">
                    <p className="line-clamp-2 text-sm text-ink-900">{product.name}</p>
                    <p className="mt-auto flex items-baseline gap-2 pt-1">
                        <span className="text-base font-bold tabular-nums text-ink-900">{peso(product.price)}</span>
                        <span className="text-xs tabular-nums text-ink-500 line-through">{peso(product.regular_price)}</span>
                    </p>
                    {!live && <p className="text-xs text-ink-600">Sale price when the event starts</p>}
                    {product.shop && <p className="flex items-center gap-1 truncate text-xs text-ink-600"><Store className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />{product.shop.name}</p>}
                </div>
            </Link>
        </li>
    );
}

export default function CampaignPage({ campaign, vouchers, products, categories, allCampaigns, serverNow }: Props) {
    const { auth } = usePage<PageProps>().props;
    const [category, setCategory] = useState<number | null>(null);
    const [busyAll, setBusyAll] = useState(false);
    const live = campaign.status === 'live';
    const shown = useMemo(() => (category ? products.filter(product => product.category?.id === category) : products), [products, category]);
    const claimable = vouchers.filter(voucher => !voucher.claimed && !voucher.sold_out).length;
    const claimAll = () => {
        if (!auth?.user) { router.visit('/login'); return; }
        setBusyAll(true);
        router.post(`/campaign/${campaign.id}/claim-all`, {}, { preserveScroll: true, only: ['vouchers', 'flash'], onFinish: () => setBusyAll(false) });
    };
    const chip = (active: boolean) => `inline-flex h-9 shrink-0 items-center rounded-full border px-3 text-sm font-medium ${active ? 'border-brand-500 bg-brand-50 text-brand-600' : 'border-line-300 bg-white text-ink-700 hover:bg-surface-100'}`;

    return (
        <>
            <Head title={`${campaign.title} — BagooPH`} />
            <div className="mx-auto w-full min-w-0 max-w-[1200px] space-y-8 px-4 pb-16 pt-4 sm:px-6">
                {campaign.banner_url && <img src={campaign.banner_url} alt={campaign.title} className="aspect-[47/22] w-full rounded-card bg-surface-100 object-cover sm:aspect-[47/16]" />}

                <header className="flex flex-col gap-4 rounded-card bg-brand-50 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-6">
                    <div>
                        <span className="rounded bg-brand-500 px-2 py-0.5 text-xs font-semibold text-white">{campaign.badge}</span>
                        <h1 className="mt-2 text-[28px] font-bold leading-[34px] text-ink-900">{campaign.title}</h1>
                    </div>
                    <Countdown campaign={campaign} serverNow={serverNow} />
                </header>

                {allCampaigns.length > 1 && (
                    <nav aria-label="Sale events" className="-mt-4 flex gap-2 overflow-x-auto pb-1">
                        {allCampaigns.map(item => (
                            <Link key={item.slug} href={`/campaign/${item.slug}`} aria-current={item.slug === campaign.slug ? 'page' : undefined} className={chip(item.slug === campaign.slug)}>
                                {item.badge}{item.status === 'upcoming' && <span className="ml-1.5 text-xs text-ink-500">Soon</span>}
                            </Link>
                        ))}
                    </nav>
                )}


                {vouchers.length > 0 && (
                    <section aria-labelledby="event-vouchers">
                        <div className="mb-3 flex items-center justify-between gap-3">
                            <h2 id="event-vouchers" className="text-[22px] font-semibold text-ink-900">Vouchers</h2>
                            {live && claimable > 1 && (
                                <button type="button" onClick={claimAll} disabled={busyAll} className="h-10 rounded-control bg-brand-500 px-4 text-sm font-semibold text-white hover:bg-brand-600 disabled:bg-line-300">
                                    {busyAll ? 'Claiming…' : `Claim all ${claimable}`}
                                </button>
                            )}
                        </div>
                        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{vouchers.map(voucher => <VoucherCard key={voucher.id} voucher={voucher} live={live} />)}</ul>
                        <p className="mt-2 text-xs text-ink-600">Claimed vouchers apply at checkout when your order qualifies.</p>
                    </section>
                )}

                <section aria-labelledby="event-deals">
                    <div className="mb-3 flex items-baseline gap-2"><h2 id="event-deals" className="text-[22px] font-semibold text-ink-900">Deals</h2><span className="text-sm text-ink-600">{products.length} {products.length === 1 ? 'product' : 'products'}</span></div>
                    {categories.length > 1 && (
                        <div className="mb-4 flex gap-2 overflow-x-auto pb-1" role="group" aria-label="Filter by category">
                            <button type="button" onClick={() => setCategory(null)} aria-pressed={category === null} className={chip(category === null)}>All</button>
                            {categories.map(item => <button key={item.id} type="button" onClick={() => setCategory(item.id)} aria-pressed={category === item.id} className={chip(category === item.id)}>{item.name}</button>)}
                        </div>
                    )}
                    {shown.length > 0 ? (
                        <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">{shown.map(product => <ProductTile key={product.sale_id} product={product} live={live} />)}</ul>
                    ) : (
                        <div className="rounded-card border border-line-200 bg-white px-6 py-12">
                            <p className="font-medium text-ink-900">No deals here yet</p>
                            <p className="mt-1 text-sm text-ink-600">{campaign.status === 'ended' ? 'This event has ended.' : 'Shops are adding their discounts. Check back soon.'}</p>
                            <Link href="/" className="mt-4 inline-flex text-sm font-semibold text-brand-600 underline underline-offset-4">Browse the marketplace</Link>
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

CampaignPage.layout = (page: ReactNode) => <BuyerLayout>{page}</BuyerLayout>;
