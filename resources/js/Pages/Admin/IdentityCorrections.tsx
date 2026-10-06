import { Head, Link } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { PaginatedData } from '@/types';

type Candidate = { id: number; name: string; role: string; birthday: string | null };
type Pending = { id: number; user_id: number; name: string; reason: string; requested_at: string };

export default function IdentityCorrections({ candidates, requests, baseUrl }: { candidates: PaginatedData<Candidate>; requests: PaginatedData<Pending>; baseUrl: string }) {
    return <DashboardLayout title="Identity correction review" subtitle="Review current evidence while keeping earlier decisions intact">
        <Head title="Identity corrections — BagooPH" />
        <div className="space-y-6">
            <section className="rounded-xl border border-slate-300 bg-white p-5">
                <h2 className="text-lg font-semibold">Pending correction requests</h2>
                <p className="mt-2 text-sm text-slate-600">Open the account, inspect every required document, and review ongoing work before deciding.</p>
                {requests.data.length === 0 && <p className="mt-4 text-sm text-slate-600">No requests are waiting for a decision.</p>}
                {requests.data.map(item => <article key={item.id} className="mt-4 border-t border-slate-300 pt-4">
                    <Link href={`${baseUrl}/users/${item.user_id}/identity-corrections`} className="font-semibold text-[#C20836]">{item.name} · Request #{item.id}</Link>
                    <p className="mt-1 whitespace-pre-line text-sm text-slate-700">{item.reason}</p>
                    <p className="mt-1 text-xs text-slate-600">{new Date(item.requested_at).toLocaleString('en-PH', { timeZone: 'Asia/Manila' })}</p>
                </article>)}
                <div className="mt-4 flex flex-wrap gap-3">{(requests.links ?? []).filter(link => link.url).map((link, index) => <Link key={index} href={link.url!} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" dangerouslySetInnerHTML={{ __html: link.label }} />)}</div>
            </section>
            <section className="rounded-xl border border-slate-300 bg-white p-5">
                <h2 className="text-lg font-semibold">Legacy details needing review</h2>
                <p className="mt-2 text-sm text-slate-600">These accounts have a missing or ineligible birth date or shop category. This list does not establish a previous approval or supply missing details.</p>
                {candidates.data.length === 0 && <p className="mt-4 text-sm text-slate-600">No candidates were found in the current records.</p>}
                {candidates.data.map(user => <article key={user.id} className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-300 pt-4">
                    <div><p className="font-semibold">{user.name}</p><p className="mt-1 text-sm capitalize text-slate-600">{user.role} · Birth date: {user.birthday?.slice(0, 10) ?? 'Not recorded'}</p></div>
                    <Link href={`${baseUrl}/users/${user.id}/identity-corrections`} className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold">Review details</Link>
                </article>)}
                <div className="mt-4 flex flex-wrap gap-3">{(candidates.links ?? []).filter(link => link.url).map((link, index) => <Link key={index} href={link.url!} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" dangerouslySetInnerHTML={{ __html: link.label }} />)}</div>
            </section>
        </div>
    </DashboardLayout>;
}
