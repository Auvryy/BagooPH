import { useForm } from '@inertiajs/react';
import LocationPinPicker from '@/Components/LocationPinPicker';
import { HubButton } from '@/Components/Logistics/Workspace';

export default function HubLocationEditor({ hub, url }: {
    hub: { name: string; latitude: number | null; longitude: number | null; location_token: string };
    url: string;
}) {
    const form = useForm({ latitude: hub.latitude, longitude: hub.longitude, location_token: hub.location_token });
    const point = form.data.latitude !== null && form.data.longitude !== null
        ? { latitude: form.data.latitude, longitude: form.data.longitude } : null;
    return <form className="mt-4 space-y-3 border-t border-slate-200 pt-4" onSubmit={event => {
        event.preventDefault();
        if (point && !form.processing) form.post(url, { preserveScroll: true });
    }}>
        <h4 className="text-sm font-semibold">Map pin for {hub.name}</h4>
        <LocationPinPicker label="Hub location" value={point} onChange={value => form.setData(previous => ({ ...previous, ...value }))} />
        {Object.values(form.errors).map(message => <p key={message} role="alert" className="text-xs text-rose-700">{message}</p>)}
        <HubButton primary disabled={!point || form.processing}>Save hub pin</HubButton>
    </form>;
}
