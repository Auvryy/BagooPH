import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, FileCheck2, FileText, ShieldCheck } from 'lucide-react';
import ReturnLayout from '@/Components/Returns/ReturnLayout';
import { financeMoney } from '@/utils/finance';
import { lossStatuses } from '@/Components/Finance/lossStatuses';
import LossControls, { LossControlState } from '@/Components/Finance/LossControls';

export interface LossOutcomeProps {
    loss: {
        id: number;
        reference: string;
        order: { id: number; order_number: string };
        mode: 'wallet' | 'cod';
        status: 'voided' | 'financially_resolved' | 'awaiting_payment';
        scope: 'buyer_return' | 'undelivered';
        company_name: string;
        amounts: Record<string, number>;
        review_reason: string | null;
        review_proof_url: string | null;
        source: { observation_reference: string; manifest_reference: string; barcode: string } | null;
        controls?: LossControlState;
        history: { id: number; reference: string; event_type: string; created_at: string; amount_cents: number;
            reason: string | null; cash_reference: string | null; proof_url: string | null }[];
    };
}

const secondary = 'inline-flex items-center justify-center gap-2 rounded-lg border border-rose-200 bg-rose-50/85 px-4 py-2.5 text-sm font-bold text-[#C20836] transition-colors hover:bg-[#E00D42] hover:text-white';
const eventLabels: Record<string, string> = {
    approved: 'Loss review approved', voided: 'Unfunded review voided', 'loss-reserve-original': 'Original funds reserved',
    'loss-contribute-company': 'Company funding received', 'loss-approve-advance': 'Virtual advance recorded',
    'loss-resolve-wallet': 'Wallet settlement recorded', 'loss-offer-company': 'Company transfer offered',
    'loss-offer-advance': 'Advance transfer offered', 'loss-receive-company': 'Company transfer received',
    'loss-receive-advance': 'Advance transfer received', 'loss-record-buyer-payment': 'Buyer refund recorded',
    'loss-record-seller-protection': 'Seller protection recorded', 'loss-confirm-buyer-receipt': 'Buyer confirmed receipt',
    'loss-confirm-seller-receipt': 'Seller confirmed receipt', 'loss-report-buyer-nonreceipt': 'Buyer reported nonreceipt',
    'loss-report-seller-nonreceipt': 'Seller reported nonreceipt', 'loss-resolve-buyer-nonreceipt': 'Buyer payment evidence reviewed',
    'loss-resolve-seller-nonreceipt': 'Seller payment evidence reviewed', 'loss-assess-zero-buyer': 'No buyer refund due',
    'loss-resolve-cash': 'Cash outcome recorded', 'loss-retire-advance': 'Advance return recorded',
    'loss-credit-requested': 'Funding credits requested', 'loss-credit-approved': 'Funding credits approved',
    'loss-credit-rejected': 'Funding credit request rejected', 'loss-disposition-recorded': 'Operational loss evidence recorded',
    'loss-found-quarantined': 'Found parcel placed in quarantine',
    'loss-accounted': 'Confirmed loss accounted separately from receipts',
    'loss-return-unused-credit': 'Unused reviewed credits returned to their issuance source',
    'loss-return-company-source': 'Unused company contribution restored',
    'loss-return-platform-source': 'Unused platform reserve source returned to its original account',
    'loss-reconciliation-approved': 'Found-parcel reconciliation approved',
    'loss-reconciliation-invalidated': 'Stale found-parcel reconciliation invalidated',
    'loss-quarantine-released': 'Found parcel physically released from quarantine',
    'loss-salvage-released': 'Found parcel released to salvage custody',
    'loss-return-buyer-compensation': 'Buyer voluntarily returned Wallet refund',
    'loss-return-seller-compensation': 'Seller voluntarily returned Wallet protection',
    'loss-restore-company-compensation': 'Reimbursed company Wallet funding from returned compensation',
    'loss-restore-escrow-compensation': 'Restored surplus returned compensation to Escrow',
    'loss-offer-buyer-compensation-return': 'Buyer offered voluntary cash refund return',
    'loss-receive-buyer-compensation-return': 'Admin confirmed buyer cash refund return',
    'loss-offer-seller-compensation-return': 'Seller offered voluntary cash protection return',
    'loss-receive-seller-compensation-return': 'Admin confirmed seller cash protection return',
    'loss-record-company-cash-return': 'Recorded company cash reimbursement',
    'loss-confirm-company-cash-return': 'Company confirmed cash reimbursement',
    'loss-report-company-cash-nonreceipt': 'Company reported cash reimbursement nonreceipt',
    'loss-resolve-company-cash-nonreceipt': 'Company cash reimbursement evidence reviewed',
};

export default function LossOutcome({ loss }: LossOutcomeProps) {
    return <ReturnLayout title="Shipment finances" subtitle={loss.order.order_number}>
        <Head title="Parcel loss outcome" />
        <main className="mx-auto max-w-4xl space-y-6 py-4">
            <Link href="/return-liabilities?view=losses" className={secondary}><ArrowLeft className="h-4 w-4" />Parcel losses</Link>
            <header className="space-y-3">
                <div className="flex flex-wrap items-center gap-3"><h1 className="flex items-center gap-2 text-2xl font-bold text-slate-900"><ShieldCheck className="h-6 w-6 text-[#E00D42]" />Parcel loss outcome</h1>
                    <span className="rounded-lg bg-rose-50 px-3 py-1.5 text-xs font-bold text-[#C20836]">{lossStatuses[loss.status]}</span></div>
                <p className="text-sm text-slate-600">{loss.order.order_number} · {loss.company_name} · {loss.mode === 'wallet' ? 'Virtual Wallet' : 'Cash on Delivery'}</p>
                <p className="break-all text-xs text-slate-500">Review {loss.reference}</p>
            </header>
            {loss.status === 'awaiting_payment' && <p role="status" className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">Funding and payment review is still open. Approval alone does not confirm payment.</p>}
            <dl className="grid gap-3 sm:grid-cols-2">{Object.entries(loss.amounts).map(([label, amount]) => <div key={label} className="rounded-lg border border-slate-300 bg-white p-5 shadow-sm">
                <dt className="text-sm text-slate-600">{label}</dt><dd className="mt-2 text-2xl font-bold text-[#C20836]">{financeMoney(amount)}</dd></div>)}</dl>
            {(loss.source || loss.review_reason || loss.review_proof_url) && <section aria-labelledby="loss-finding-title" className="space-y-4 rounded-lg border border-slate-300 bg-white p-5 shadow-sm sm:p-6">
                <h2 id="loss-finding-title" className="flex items-center gap-2 font-bold text-slate-900"><FileCheck2 className="h-5 w-5 text-[#E00D42]" />Independent finding</h2>
                {loss.source && <dl className="grid gap-4 text-sm sm:grid-cols-3">{[['Observation', loss.source.observation_reference], ['Manifest', loss.source.manifest_reference], ['Parcel barcode', loss.source.barcode]].map(([label, value]) => <div key={label}><dt className="text-xs text-slate-600">{label}</dt><dd className="mt-1 break-all font-semibold text-slate-900">{value}</dd></div>)}</dl>}
                {loss.review_reason && <p className="whitespace-pre-wrap break-words text-sm leading-6 text-slate-700">{loss.review_reason}</p>}
                {loss.review_proof_url && <a href={loss.review_proof_url} className={secondary}><FileText className="h-4 w-4" />Private investigation evidence</a>}
            </section>}
            {loss.controls && <LossControls planId={loss.id} value={loss.controls} summaryLabels={Object.keys(loss.amounts)} />}
            <section aria-labelledby="loss-history-title" className="overflow-hidden rounded-lg border border-slate-300 bg-white shadow-sm">
                <h2 id="loss-history-title" className="border-b border-slate-300 p-5 font-bold text-slate-900">Financial history</h2>
                <ol className="divide-y divide-slate-200">{loss.history.map(event => <li key={event.id} className="space-y-3 p-5">
                    <div className="flex flex-wrap items-start justify-between gap-3"><div><p className="text-sm font-bold text-slate-900">{eventLabels[event.event_type] ?? 'Financial update recorded'}</p>
                        <time dateTime={event.created_at} className="mt-1 block text-xs text-slate-500">{new Date(event.created_at).toLocaleString('en-PH', { timeZone: 'Asia/Manila' })}</time></div>
                        {event.amount_cents > 0 && <span className="text-sm font-bold text-[#C20836]">{financeMoney(event.amount_cents)}</span>}</div>
                    {event.reason && <p className="whitespace-pre-wrap break-words text-sm leading-6 text-slate-700">{event.reason}</p>}
                    {event.cash_reference && <p className="break-all text-xs text-slate-600">Transfer reference: {event.cash_reference}</p>}
                    {event.proof_url && <a href={event.proof_url} className={secondary}><FileText className="h-4 w-4" />Private transfer evidence</a>}
                </li>)}</ol>
            </section>
        </main>
    </ReturnLayout>;
}
