import { useForm } from '@inertiajs/react';
import { HubButton, hubInput } from '@/Components/Logistics/Workspace';
import type { CompanyRiderIntake } from '@/types/riderCompany';

export default function InviteExistingRider({ workspace, onClose }: { workspace: Extract<CompanyRiderIntake, { available: true }>; onClose?: () => void }) {
    const form = useForm({ email: '', hub_id: workspace.review_hub_id ?? workspace.hubs?.[0]?.id ?? '', vehicle_id: '',
        terms: '', request_token: crypto.randomUUID() });
    const hub = workspace.hubs?.find(item => item.id === form.data.hub_id);
    return <form className="space-y-4 rounded-card border border-line-200 bg-white p-5" onSubmit={event => {
        event.preventDefault();
        form.post(route('hub.rider-invitations'), { preserveScroll: true, onSuccess: () => { form.reset(); form.setData('request_token', crypto.randomUUID()); onClose?.(); } });
    }}>
        <h2 className="text-base font-semibold text-ink-900">Invite an existing rider</h2>
        <div className="grid gap-4 sm:grid-cols-2">
            <label className="space-y-1 text-sm font-medium text-ink-900"><span>Rider sign-in email</span><input type="email" required maxLength={255} value={form.data.email} onChange={event => form.setData('email', event.target.value)} className={hubInput} /></label>
            <label className="space-y-1 text-sm font-medium text-ink-900"><span>Working hub</span><select required value={form.data.hub_id} onChange={event => { form.setData('hub_id', event.target.value); form.setData('vehicle_id', ''); }} className={hubInput}>{workspace.hubs?.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
            <label className="space-y-1 text-sm font-medium text-ink-900"><span>Vehicle</span><select value={form.data.vehicle_id} onChange={event => form.setData('vehicle_id', event.target.value)} className={hubInput}><option value="">Rider's own vehicle</option>{workspace.vehicles?.filter(item => item.hub_id === hub?.id).map(item => <option key={item.id} value={item.id}>{item.plate}</option>)}</select></label>
        </div>
        <label className="block space-y-1 text-sm font-medium text-ink-900"><span>Working arrangement</span><textarea required minLength={5} maxLength={2000} rows={3} value={form.data.terms} onChange={event => form.setData('terms', event.target.value)} className={hubInput} /></label>
        <p className="text-sm text-ink-600">The approved rider receives an offer and must accept it before joining this hub. The invitation expires in seven days.</p>
        {Object.entries(form.errors).map(([field, message]) => <p key={field} role="alert" className="text-sm text-brand-700">{message}</p>)}
        <div className="flex gap-2"><HubButton primary type="submit" disabled={form.processing}>{form.processing ? 'Sending…' : 'Send invitation'}</HubButton>{onClose && <HubButton type="button" onClick={onClose} disabled={form.processing}>Cancel</HubButton>}</div>
    </form>;
}
