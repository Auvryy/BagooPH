import { Link } from '@inertiajs/react';
import { courierButton } from '@/Components/CourierUI';
import type { CompanyRequestPage } from '@/types/riderCompany';

export default function CompanyRequestPagination({ page, label, courier = false, busy = false }: {
    page: CompanyRequestPage<unknown>; label: string; courier?: boolean; busy?: boolean;
}) {
    if (page.last_page <= 1) return null;
    const button = courier ? courierButton : 'inline-flex min-h-10 items-center justify-center rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42]';
    return <nav aria-label={label} className="mt-4 flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-slate-600">Page {page.current_page} of {page.last_page}</p>
        <div className="flex gap-2">
            {page.prev_page_url && !busy && <Link href={page.prev_page_url} preserveScroll className={button} aria-label={`Previous ${label.toLowerCase()}`}>Previous</Link>}
            {page.next_page_url && !busy && <Link href={page.next_page_url} preserveScroll className={button} aria-label={`Next ${label.toLowerCase()}`}>Next</Link>}
        </div>
    </nav>;
}
