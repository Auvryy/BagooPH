import { usePage } from '@inertiajs/react';

export default function CatalogueErrors() {
    const { errors } = usePage().props;
    const fields = ['search', 'category', 'sort', 'min_price', 'max_price', 'rating', 'in_stock', 'page'];
    const messages = Object.entries(errors ?? {}).filter(([field, message]) => fields.includes(field) && typeof message === 'string');
    if (!messages.length) return null;
    return <div role="alert" className="mx-auto max-w-7xl space-y-1 rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-700">
        {messages.map(([field, message]) => <p key={field}>{String(message)}</p>)}
    </div>;
}
