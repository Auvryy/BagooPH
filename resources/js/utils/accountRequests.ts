import axios from 'axios';
import { router } from '@inertiajs/react';

export function requestErrors(error: unknown): Record<string, string> {
    if (axios.isAxiosError(error)) {
        const details = error.response?.data?.errors as Record<string, string[]> | undefined;
        if (details) return Object.fromEntries(Object.entries(details).map(([field, messages]) => [field, messages[0]]));
        const status = error.response?.status;
        return { request: status === 419 ? 'Your session expired. Reload this page and try again.' : status === 403 ? 'Your account cannot make this change.' : error.response?.data?.message ?? 'We could not save your change. Check your connection and try again.' };
    }
    return { request: error instanceof Error ? error.message : 'We could not save your change. Please try again.' };
}

export function refreshPage(): Promise<void> {
    return new Promise((resolve, reject) => {
        let succeeded = false;
        router.reload({
            onSuccess: () => { succeeded = true; resolve(); },
            onFinish: () => {
                if (!succeeded) reject(new Error('Your change was saved, but the updated view could not load. Reload the current view to see it.'));
            },
        });
    });
}
