import { Link } from '@inertiajs/react';
import axios from 'axios';
import { useState } from 'react';
import { DialogTitle } from '@headlessui/react';
import { X } from 'lucide-react';
import Modal from '@/Components/Modal';
import type { Notice } from '@/Components/NotificationMenu';

export default function AccountStatusNotificationDialog({ notice, onClose, onMarkedRead }: {
    notice: Notice;
    onClose: () => void;
    onMarkedRead: (readAt: string) => void;
}) {
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    const markRead = async () => {
        if (saving || notice.read_at) return;
        setSaving(true);
        setError('');
        try {
            const response = await axios.patch<{ read_at: string }>(`/notifications/${notice.id}/read`, {}, { headers: { Accept: 'application/json' } });
            onMarkedRead(response.data.read_at);
            onClose();
        } catch {
            setError('We could not mark this account update as read. Please try again.');
        } finally {
            setSaving(false);
        }
    };

    return <Modal show onClose={onClose} maxWidth="md">
        <section className="rounded-lg border border-slate-300 bg-white p-5 text-slate-900">
            <div className="flex items-start justify-between gap-3">
                <div><p className="text-xs font-semibold uppercase tracking-wide text-[#C20836]">Account status update</p><DialogTitle className="mt-1 text-lg font-bold">{notice.data.title ?? 'Your account status changed'}</DialogTitle></div>
                <button type="button" onClick={onClose} aria-label="Close account update" className="rounded-lg p-2 text-slate-600 hover:bg-slate-100 focus-visible:ring-2 focus-visible:ring-[#E00D42]"><X className="h-4 w-4" aria-hidden="true" /></button>
            </div>
            <p className="mt-3 whitespace-pre-line text-sm leading-relaxed text-slate-700">{notice.data.body ?? 'Your account status has changed. Open Account status for the current review details.'}</p>
            {error && <p role="alert" className="mt-3 text-sm text-rose-700">{error}</p>}
            <div className="mt-5 flex flex-wrap justify-end gap-2">
                <button type="button" onClick={onClose} className="min-h-10 rounded-lg border border-slate-300 px-4 text-sm font-semibold hover:bg-slate-50">{notice.read_at ? 'Close' : 'Later'}</button>
                {!notice.read_at && <button type="button" onClick={markRead} disabled={saving} className="min-h-10 rounded-lg bg-[#E00D42] px-4 text-sm font-semibold text-white hover:bg-[#C20836] disabled:opacity-50">{saving ? 'Saving…' : 'Mark as read'}</button>}
                <Link href="/account/status" onClick={onClose} className="inline-flex min-h-10 items-center rounded-lg px-3 text-sm font-semibold text-[#C20836] underline underline-offset-2">View account status</Link>
            </div>
        </section>
    </Modal>;
}
