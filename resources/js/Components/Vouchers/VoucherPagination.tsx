import { Link } from '@inertiajs/react';

export default function VoucherPagination({ page, label = 'Offer pages' }: { page: { current_page: number; last_page: number; links?: { url: string | null; label: string; active: boolean }[] }; label?: string }) {
    if (page.last_page <= 1) return null;
    return <nav aria-label={label} className="flex flex-wrap items-center justify-end gap-2 p-4 text-xs">
        {(page.links ?? []).map((link, index) => {
            const text = index === 0 ? 'Previous' : index === (page.links?.length ?? 0) - 1 ? 'Next' : link.label.replace(/<[^>]*>/g, '');
            return link.url ? <Link key={index} href={link.url} preserveScroll className={`rounded-lg border px-3 py-2 font-semibold ${link.active ? 'border-[#E00D42] bg-[#E00D42] text-white' : 'border-slate-300 text-slate-700 hover:bg-slate-50'}`} aria-current={link.active ? 'page' : undefined}>{text}</Link>
                : <span key={index} className="rounded-lg border border-slate-300 px-3 py-2 text-slate-400">{text}</span>;
        })}
    </nav>;
}
