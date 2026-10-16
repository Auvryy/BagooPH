import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

interface VisitCallbacks {
    onSuccess?: () => void;
    onError?: () => void;
    onFinish?: () => void;
}

export function openNotice(id: string, callbacks: VisitCallbacks = {}) {
    router.patch(`/notifications/${id}/read`, { open: true }, { preserveScroll: true, ...callbacks });
}

export default function useNotificationAction(onOpened?: () => void) {
    const [reading, setReading] = useState<string | null>(null);
    const [error, setError] = useState('');
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

    const visit = (id: string, open: boolean) => {
        if (pending.current) return;
        pending.current = true;
        setReading(id);
        setError('');
        const callbacks = {
            onSuccess: () => { if (open) onOpened?.(); },
            onError: () => setError('The update could not be opened or marked as read. Please try again.'),
            onFinish: () => { pending.current = false; setReading(null); },
        };
        if (open) openNotice(id, callbacks);
        else router.patch(`/notifications/${id}/read`, {}, { preserveScroll: true, ...callbacks });
    };

    return { reading, error, open: (id: string) => visit(id, true), markRead: (id: string) => visit(id, false) };
}
