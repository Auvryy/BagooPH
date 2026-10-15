import { Link } from '@inertiajs/react';
import { History, Pagination, dateLabel, stateLabel } from '@/types/advertisements';

export default function PromotionHistory({ history, baseUrl, id }: { history: Pagination<History>; baseUrl: string; id: number }) {
    return <section className="space-y-3"><h2 className="text-lg font-bold">Decision history</h2>
        <ol className="divide-y divide-slate-300 rounded-lg border border-slate-300 bg-white">{history.data.map((entry) => <li key={entry.id} className="space-y-1 p-4">
            <p className="text-sm font-bold">{stateLabel(entry.action.replaceAll('_', ' '))} · {entry.actor}</p>
            <p className="text-xs text-slate-500">{dateLabel(entry.created_at)} (Manila) · Version {entry.before_version} → {entry.after_version}</p>
            {entry.reason && <p className="text-sm text-slate-700">{entry.reason}</p>}
            <Link href={`${baseUrl}/${id}/preview?revision=${entry.revision_id}`} className="inline-block text-sm text-[#E00D42] underline">Preview recorded revision</Link>
        </li>)}</ol>
        <nav aria-label="Decision history pages" className="flex gap-4 text-sm">{history.prev_page_url && <Link href={history.prev_page_url} preserveScroll preserveState>Previous decisions</Link>}{history.next_page_url && <Link href={history.next_page_url} preserveScroll preserveState>Next decisions</Link>}</nav>
    </section>;
}
