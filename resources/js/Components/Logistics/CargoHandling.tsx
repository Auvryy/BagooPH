import { hubInput } from '@/Components/Logistics/Workspace';

export type HandlingInput = { weight_kg: string; length_cm: string; width_cm: string; height_cm: string; confirmed: boolean };
export type Handling = { weight_kg: number; length_cm: number; width_cm: number; height_cm: number; source: string; baggable: boolean };
export const emptyHandling = (): HandlingInput => ({ weight_kg: '', length_cm: '', width_cm: '', height_cm: '', confirmed: false });

export function HandlingFields({ value, onChange, disabled = false }: { value: HandlingInput; onChange: (value: HandlingInput) => void; disabled?: boolean }) {
    return <details className="rounded-lg border border-slate-300 bg-white p-4">
        <summary className="cursor-pointer text-sm font-semibold text-[#C20836]">Outer-package readings</summary>
        <p className="mt-2 text-xs text-slate-600">Required for multi-item or incomplete declarations. These handling readings do not change the purchased shipping fee.</p>
        <div className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
            {([['weight_kg', 'Weight (kg)'], ['length_cm', 'Length (cm)'], ['width_cm', 'Width (cm)'], ['height_cm', 'Height (cm)']] as const).map(([key, label]) =>
                <label key={key} className="text-xs font-semibold text-slate-700">{label}<input type="number" inputMode="decimal" min="0.001" max="10000" step="0.001" disabled={disabled} className={`${hubInput} mt-1.5`} value={value[key]} onChange={event => onChange({ ...value, [key]: event.target.value })} /></label>)}
        </div>
        <label className="mt-3 flex items-start gap-2 text-xs text-slate-700"><input type="checkbox" disabled={disabled} checked={value.confirmed} onChange={event => onChange({ ...value, confirmed: event.target.checked })} className="mt-0.5 rounded border-slate-300 text-[#E00D42] focus:ring-[#E00D42]" />I checked the weight and outside dimensions of this actual parcel.</label>
    </details>;
}

export function HandlingSummary({ value }: { value?: Handling | null }) {
    if (!value) return <span className="text-xs text-slate-500">Handling readings not retained</span>;
    return <span className="block text-xs text-slate-600">{value.weight_kg} kg · {value.length_cm} × {value.width_cm} × {value.height_cm} cm · {value.source === 'operator_reading' ? 'Operator reading' : 'Purchased declaration'}</span>;
}

export function CapacityMeter({ value, max, label, unit }: { value: number | null; max: number; label: string; unit: string }) {
    return <div className="rounded-lg border border-rose-200 bg-white p-3">
        <div className="flex justify-between gap-2 text-xs text-slate-600"><span>{label}</span><strong className="text-[#9F1239]">{value === null ? 'Unknown' : value} / {max} {unit}</strong></div>
        <progress aria-label={label} className="mt-2 h-2 w-full accent-[#E00D42]" max={max} value={value ?? 0} />
    </div>;
}
