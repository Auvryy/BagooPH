import { hubInput } from '@/Components/Logistics/Workspace';

export type HandlingInput = { weight_kg: string; length_cm: string; width_cm: string; height_cm: string; confirmed: boolean };
export type Handling = { weight_kg: number; length_cm: number; width_cm: number; height_cm: number; source: string; baggable: boolean };
export const emptyHandling = (): HandlingInput => ({ weight_kg: '', length_cm: '', width_cm: '', height_cm: '', confirmed: false });

/**
 * Outer-package readings. Always visible (never hidden in a fold-out) because the
 * server can require them; pass `required` when the current action needs them.
 */
export function HandlingFields({ value, onChange, disabled = false, required = false }: { value: HandlingInput; onChange: (value: HandlingInput) => void; disabled?: boolean; required?: boolean }) {
    return <fieldset className="rounded-control border border-line-200 bg-surface-50 p-4">
        <legend className="sr-only">Package readings</legend>
        <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="text-sm font-medium text-ink-900">Package readings</p>
            {required
                ? <span className="rounded-chip bg-brand-50 px-1.5 py-0.5 text-xs font-medium text-brand-600">Required</span>
                : <span className="text-xs text-ink-600">Needed for multi-item or incomplete declarations</span>}
        </div>
        <div className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
            {([['weight_kg', 'Weight', 'kg'], ['length_cm', 'Length', 'cm'], ['width_cm', 'Width', 'cm'], ['height_cm', 'Height', 'cm']] as const).map(([key, label, unit]) =>
                <label key={key} className="text-[13px] font-medium text-ink-700">
                    {label}{required && <span className="text-brand-600" aria-hidden="true"> *</span>}
                    <span className="relative mt-1.5 block">
                        <input type="number" inputMode="decimal" min="0.001" max="10000" step="0.001" required={required} disabled={disabled}
                            className={`${hubInput} pr-10 tabular-nums`} value={value[key]} onChange={event => onChange({ ...value, [key]: event.target.value })} />
                        <span aria-hidden="true" className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs text-ink-500">{unit}</span>
                    </span>
                </label>)}
        </div>
        <label className="mt-3 flex items-start gap-2 text-[13px] text-ink-700">
            <input type="checkbox" required={required} disabled={disabled} checked={value.confirmed} onChange={event => onChange({ ...value, confirmed: event.target.checked })} className="mt-0.5 rounded-chip border-line-300 text-brand-500 focus:ring-brand-500" />
            I checked the weight and outside dimensions of this parcel.
        </label>
        <p className="mt-2 text-xs text-ink-500">Readings do not change the purchased shipping fee.</p>
    </fieldset>;
}

export function HandlingSummary({ value }: { value?: Handling | null }) {
    if (!value) return <span className="text-xs text-slate-500">Handling readings not retained</span>;
    return <span className="block text-xs text-slate-600">{value.weight_kg} kg · {value.length_cm} × {value.width_cm} × {value.height_cm} cm · {value.source === 'operator_reading' ? 'Operator reading' : 'Purchased declaration'}</span>;
}

export function CapacityMeter({ value, max, label, unit }: { value: number | null; max: number; label: string; unit: string }) {
    const pct = value === null || max <= 0 ? 0 : Math.min(100, (value / max) * 100);
    return <div className="rounded-control bg-surface-50 p-3">
        <p className="text-xs text-ink-600">{label}</p>
        <p className="mt-0.5 whitespace-nowrap text-sm tabular-nums text-ink-900"><strong className="font-semibold">{value === null ? 'Unknown' : value}</strong><span className="text-ink-500"> / {max}{unit ? ` ${unit}` : ''}</span></p>
        <span role="progressbar" aria-label={label} aria-valuemin={0} aria-valuemax={max} aria-valuenow={value ?? undefined} className="mt-2 block h-1.5 overflow-hidden rounded-full bg-line-200">
            <span className={`block h-full rounded-full ${pct >= 90 ? 'bg-brand-500' : pct >= 70 ? 'bg-warning' : 'bg-success'}`} style={{ width: `${pct}%` }} />
        </span>
    </div>;
}
