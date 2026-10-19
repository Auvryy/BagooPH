import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

interface VisitCallbacks {
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
    onFinish?: () => void;
}

export function openNotice(id: string, callbacks: VisitCallbacks = {}) {
    router.patch(`/notifications/${id}/read`, { open: true }, { preserveScroll: true, ...callbacks });
}

export default function useNotificationAction(onOpened?: () => void) {
    const [reading, setReading] = useState<string | null>(null);
    const [error, setError] = useState('');
    const [success, setSuccess] = useState('');
    const pending = useRef(false);

    useEffect(() => {
        const fail = (message: string) => {
            setError(message);
            pending.current = false;
            setReading(null);
        };
        const stopInvalid = router.on('invalid', event => {
            if (!pending.current) return;
            event.preventDefault();
            fail(event.detail.response.status === 419
                ? 'Your session expired. Reload the page and sign in again before retrying.'
                : 'The update could not be opened or marked as read. Please try again.');
        });
        const stopException = router.on('exception', event => {
            if (!pending.current) return;
            event.preventDefault();
            fail('The result could not be confirmed. Check your connection and try again.');
        });
        return () => { stopInvalid(); stopException(); };
    }, []);

    const visit = (id: string, open: boolean, snapshot?: { id: string; filter: 'all' | 'unread' }) => {
        if (pending.current) return;
        pending.current = true;
        setReading(id);
        setError('');
        setSuccess('');
        const callbacks = {
            onSuccess: () => { if (open) onOpened?.(); if (snapshot) setSuccess('Your earlier unread updates have been marked as read. New updates stay unread.'); },
            onError: (errors: Record<string, string>) => setError(Object.values(errors ?? {})[0] || 'The update could not be opened or marked as read. Please try again.'),
            onFinish: () => { pending.current = false; setReading(null); },
        };
        if (snapshot) router.patch('/notifications/read-all', { snapshot: snapshot.id, filter: snapshot.filter }, { preserveScroll: true, ...callbacks });
        else if (open) openNotice(id, callbacks);
        else router.patch(`/notifications/${id}/read`, {}, { preserveScroll: true, ...callbacks });
    };

    return { reading, error, success, open: (id: string) => visit(id, true), markRead: (id: string) => visit(id, false),
        markAll: (snapshot: string, filter: 'all' | 'unread') => visit('all', false, { id: snapshot, filter }) };
}
