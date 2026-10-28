import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { KeyRound, MapPin, X } from 'lucide-react';
import { HubButton, HubPanel, hubInput } from './Workspace';

export type CoverageHub = { id: number; name: string; tier: string; parent_hub_id: number | null; postal_codes: string[] | null; coverage_barangays?: string[] | null; coverage_token: string; is_active: boolean };

export function CoverageEditor({ hub, hubs, url, onClose }: { hub: CoverageHub; hubs: CoverageHub[]; url: string; onClose: () => void }) {
    const [postals, setPostals] = useState(hub.postal_codes?.join(', ') ?? '');
    const [barangays, setBarangays] = useState(hub.coverage_barangays?.join(', ') ?? '');
    const form = useForm({ parent_hub_id: String(hub.parent_hub_id ?? ''), postal_codes: hub.postal_codes ?? [] as string[], coverage_barangays: hub.coverage_barangays ?? [] as string[], coverage_token: hub.coverage_token });
    return <HubPanel title={`Service coverage · ${hub.name}`} description="Only future orders use this coverage. Purchased parcels keep their original route." icon={<MapPin className="h-4 w-4" />}>
        <form className="space-y-4" onSubmit={event => { event.preventDefault(); form.post(url, { preserveScroll: true, onSuccess: onClose }); }}>
            <label className="block text-xs font-semibold text-slate-700">Parent Mother Hub<select required className={`${hubInput} mt-1.5`} value={form.data.parent_hub_id} onChange={event => form.setData('parent_hub_id', event.target.value)}><option value="">Choose the actual parent</option>{hubs.filter(item => item.tier === 'regional_mother_hub' && item.is_active).map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
            <label className="block text-xs font-semibold text-slate-700">Served postal codes, separated by commas<input required className={`${hubInput} mt-1.5`} value={postals} placeholder="4008, 4009" onChange={event => { setPostals(event.target.value); form.setData('postal_codes', event.target.value.split(',').map(value => value.trim()).filter(Boolean)); }} /></label>
            <label className="block text-xs font-semibold text-slate-700">Barangay restrictions (optional)<input className={`${hubInput} mt-1.5`} value={barangays} onChange={event => { setBarangays(event.target.value); form.setData('coverage_barangays', event.target.value.split(',').map(value => value.trim()).filter(Boolean)); }} /><span className="mt-1.5 block font-normal leading-5 text-slate-500">Leave blank to serve every barangay in the listed postal areas. Otherwise, separate the served barangay names with commas.</span></label>
            {Object.values(form.errors).map((message, index) => <p key={index} role="alert" className="text-sm text-rose-700">{message}</p>)}
            <div className="flex gap-2"><HubButton primary disabled={form.processing}>Save future coverage</HubButton><HubButton type="button" onClick={onClose}><X className="h-4 w-4" />Cancel</HubButton></div>
        </form>
    </HubPanel>;
}

export function TemporaryLoginEditor({ person, url, onClose }: { person: { name: string; email: string }; url: string; onClose: () => void }) {
    const form = useForm({ current_password: '', password: '' });
    const [visible, setVisible] = useState(false);
    return <HubPanel title={`Replace temporary login · ${person.name}`} description={`For ${person.email}. Share the replacement privately; activation is still required.`} icon={<KeyRound className="h-4 w-4" />}>
        <form className="space-y-4" onSubmit={event => { event.preventDefault(); form.post(url, { preserveScroll: true, onSuccess: onClose, onFinish: () => form.reset('current_password', 'password') }); }}>
            <label className="block text-xs font-semibold text-slate-700">Your current company-owner password<input required type="password" autoComplete="current-password" className={`${hubInput} mt-1.5`} value={form.data.current_password} onChange={event => form.setData('current_password', event.target.value)} /></label>
            <label className="block text-xs font-semibold text-slate-700">New temporary password<div className="mt-1.5 flex gap-2"><input required autoComplete="new-password" type={visible ? 'text' : 'password'} className={hubInput} value={form.data.password} onChange={event => form.setData('password', event.target.value)} /><HubButton type="button" onClick={() => { form.setData('password', `Bg!${crypto.randomUUID()}aA8`); setVisible(true); }}>Generate</HubButton></div></label>
            <p className="text-xs leading-5 text-slate-500">The old temporary login stops working. The new one expires after seven days.</p>
            {Object.values(form.errors).map((message, index) => <p key={index} role="alert" className="text-sm text-rose-700">{message}</p>)}
            <div className="flex gap-2"><HubButton primary disabled={form.processing}>Replace temporary login</HubButton><HubButton type="button" onClick={onClose}>Cancel</HubButton></div>
        </form>
    </HubPanel>;
}
