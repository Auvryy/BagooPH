import { Head, Link, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { reviewStatusLabels, type ReviewRequest } from '@/types/accountReviews';

type Row = ReviewRequest & { owner: { id: number; name: string; email: string; role: string } };
export default function AccountReviews({ requests, filter }: { requests: { data: Row[]; links: { url: string | null; label: string; active: boolean }[] }; filter: string }) {
    return <DashboardLayout title="Account review requests" subtitle="Review owner explanations while keeping approval and account activity separate">
        <Head title="Account review requests — BagooPH" />
        <div className="space-y-5">
            <label className="block text-sm font-semibold" htmlFor="request-status">Request status
                <select id="request-status" value={filter} onChange={event => router.get(route('admin.account-reviews.index'), { status: event.target.value })}
                    className="ml-3 rounded-lg border border-slate-300 bg-white text-sm focus:border-[#E00D42] focus:ring-[#E00D42]">
                    <option value="all">All requests</option>{Object.entries(reviewStatusLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                </select>
            </label>
            <div className="rounded-xl border border-slate-300 bg-white">
                {requests.data.length === 0 ? <p className="px-5 py-16 text-center text-sm text-slate-600">No review requests in this view.</p> : requests.data.map(item => <article key={item.id} className="flex flex-wrap items-center justify-between gap-4 border-b border-slate-300 p-5 last:border-0">
                    <div className="min-w-0"><h2 className="font-semibold text-slate-900">{item.owner.name}</h2><p className="mt-1 break-all text-sm text-slate-600">{item.owner.email} · {item.owner.role === 'courier' ? 'Rider' : item.owner.role}</p><p className="mt-1 text-sm text-slate-700">Request #{item.id} · {item.subject_type} · {reviewStatusLabels[item.status]}</p></div>
                    <Link href={route('admin.account-reviews.show', item.id)} className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold hover:bg-slate-50">Open request</Link>
                </article>)}
            </div>
            <nav aria-label="Review request pages" className="flex flex-wrap gap-2">{requests.links.map((link, index) => {
                const label = index === 0 ? 'Previous' : index === requests.links.length - 1 ? 'Next' : link.label;
                return link.url ? <Link key={index} href={link.url} aria-current={link.active ? 'page' : undefined} className={`rounded-lg border px-3 py-2 text-sm font-semibold ${link.active ? 'border-[#E00D42] bg-[#E00D42] text-white' : 'border-slate-300 bg-white'}`}>{label}</Link>
                    : <span key={index} aria-disabled="true" className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-400">{label}</span>;
            })}</nav>
        </div>
    </DashboardLayout>;
}
