import { useEffect, useRef, useState } from 'react';
import { invalidateCatalogue } from '@/utils/catalogueRuntime';

interface MessageInput {
    receiver_id?: number;
    shop_id?: number | null;
    product_id?: number | null;
    order_id?: number | null;
    message: string;
}

export default function useMarketplaceComposer(context: string | number | undefined) {
    const [image, setImage] = useState<File | null>(null);
    const [error, setError] = useState('');
    const [sending, setSending] = useState(false);
    const busy = useRef(false);
    const active = useRef(context); active.current = context;
    const intent = useRef<{ signature: string; image: File | null; key: string } | null>(null);
    useEffect(() => { setImage(null); setError(''); intent.current = null; }, [context]);

    const selectImage = (file: File | null) => {
        if (file && (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024)) {
            setError('Choose a JPG, PNG or WEBP image, up to 5 MB.'); return;
        }
        setImage(file); setError('');
    };

    const send = async <T extends { id: number }>(input: MessageInput): Promise<T | undefined> => {
        if (busy.current) return;
        const payload = { ...input, message: input.message.trim() };
        if (!payload.message && !image) { setError('Type a message or choose a photo.'); return; }
        if (payload.message.length > 1000) { setError('Keep your message within 1,000 characters.'); return; }
        const signature = JSON.stringify(payload);
        if (!intent.current || intent.current.signature !== signature || intent.current.image !== image) intent.current = { signature, image, key: crypto.randomUUID() };
        const requestContext = context;
        busy.current = true; setSending(true); setError('');
        try {
            const body = new FormData();
            Object.entries(payload).forEach(([key, value]) => { if (value !== null && value !== undefined) body.append(key, String(value)); });
            body.append('request_key', intent.current.key);
            if (image) body.append('attachment', image);
            const response = await fetch('/chat/send', { method: 'POST', credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '' }, body });
            if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Your session may have changed. Reload the page and sign in again if needed.');
            const result = await response.json();
            if (!response.ok || !result.success || typeof result.message?.id !== 'number') {
                const field = Object.values(result.errors ?? {}).flat()[0];
                throw new Error(response.status === 419 ? 'Your session has expired. Reload the page and try again.' : response.status >= 500 ? 'Your message was not confirmed. Try again.' : typeof field === 'string' ? field : result.message || result.error || 'Your message was not confirmed. Try again.');
            }
            invalidateCatalogue();
            if (active.current !== requestContext) return;
            setImage(null); intent.current = null;
            return result.message as T;
        } catch (failure) {
            if (active.current === requestContext) setError(failure instanceof Error ? failure.message : 'Your message was not confirmed. Try again.');
        } finally { busy.current = false; setSending(false); }
    };
    return { image, error, sending, selectImage, send };
}
