import { router } from '@inertiajs/react';
import { useEffect, type MutableRefObject } from 'react';

export default function useCourierRequestError(pending: MutableRefObject<boolean>, onFailure: (message: string) => void) {
    useEffect(() => {
        const stopException = router.on('exception', (event) => {
            if (!pending.current) return;
            onFailure('Could not confirm the result. Check your connection and refresh the records before retrying.');
            event.preventDefault();
        });
        const stopInvalid = router.on('invalid', (event) => {
            if (!pending.current) return;
            onFailure(event.detail.response.status === 419
                ? 'Your session expired. Refresh the page and sign in again before retrying.'
                : 'The server could not confirm the result. Refresh the records before retrying.');
            event.preventDefault();
        });
        return () => { stopException(); stopInvalid(); };
    }, [pending, onFailure]);
}
