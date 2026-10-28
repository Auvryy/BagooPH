import type { ShippingCalculation as Calculation } from '@/types';

const kg = (grams: number) => `${(grams / 1000).toLocaleString('en-PH', { maximumFractionDigits: 3 })} kg`;
const php = (cents: number) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(cents / 100);

export default function ShippingCalculation({ calculation, selfPickup = false }: { calculation?: Calculation | null; selfPickup?: boolean }) {
    if (!calculation) return null;
    const missing = calculation.missing_measurement_product_ids?.length > 0;
    return <div className="space-y-2 text-xs leading-5 text-slate-600">
        {calculation.company_name && <p className="font-semibold text-slate-800">{calculation.company_name}</p>}
        {missing ? <p>Some packed measurements are not recorded. This quote uses the published flat base fee.</p> : <dl className="grid grid-cols-3 gap-2"><div><dt>Actual weight</dt><dd className="font-semibold text-slate-800">{kg(calculation.actual_weight_grams)}</dd></div><div><dt>Volumetric</dt><dd className="font-semibold text-slate-800">{kg(calculation.volumetric_weight_grams)}</dd></div><div><dt>Billable weight</dt><dd className="font-semibold text-slate-800">{kg(calculation.billable_weight_grams)}</dd></div></dl>}
        <p>{selfPickup ? 'Hub collection: no doorstep shipping charge.' : `Base ${php(calculation.base_fee_cents)} + ${calculation.additional_kilograms} additional kg × ${php(calculation.additional_kg_cents)}. Included: ${kg(calculation.included_weight_grams)}.`}</p>
        {calculation.route?.length ? <p><span className="font-semibold">Route:</span> {calculation.route.map(hub => hub.code).join(' → ')}{calculation.same_hub_bypass ? ' · Local child-hub delivery' : ''}</p> : null}
    </div>;
}
