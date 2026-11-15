import { ReactNode } from 'react';

export const inputClasses = 'block w-full h-10 rounded-control border border-line-300 bg-white px-3 text-sm text-ink-900 placeholder:text-ink-500 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/40 disabled:bg-surface-100 disabled:text-ink-500 aria-[invalid=true]:border-brand-700';

export const labelClasses = 'mb-1.5 block text-[13px] font-medium text-ink-700';

/**
 * Helper or error text below a field. Error text pairs color with wording.
 * Plain function so the message renders inline with the field markup.
 */
export function fieldMessage({ id, error, hint }: { id?: string; error?: string; hint?: ReactNode }) {
    if (error) return <p id={id} className="mt-1.5 text-[13px] text-brand-700">{error}</p>;
    if (hint) return <p className="mt-1.5 text-xs text-ink-600">{hint}</p>;
    return null;
}

export const FieldMessage = fieldMessage;
