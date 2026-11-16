/**
 * Number fields accept numbers only. Browsers let letters, "e" and "+" into <input type="number">
 * (Firefox even shows them), so one document-level guard checks every typed or pasted change before
 * it lands: digits always, a decimal point only where the field allows decimals, a minus sign only
 * where negatives are valid. Text fields marked inputmode="numeric" or "decimal" follow the same rule.
 */
export interface NumericRules { decimal: boolean; negative: boolean }

export function numericRules(input: HTMLInputElement): NumericRules | null {
    const mode = input.getAttribute('inputmode');
    if (input.type !== 'number' && mode !== 'numeric' && mode !== 'decimal') return null;
    const step = input.getAttribute('step');
    const decimal = mode === 'decimal' || step === 'any' || (step !== null && step.includes('.'))
        || (input.type === 'number' && step === null && mode !== 'numeric');
    const min = input.getAttribute('min');
    return { decimal, negative: min !== null && Number(min) < 0 };
}

/** Whether `next`, the value the field would hold after the change, is a number in progress. */
export function acceptsNumeric(next: string, rules: NumericRules): boolean {
    const sign = rules.negative ? '-?' : '';
    const pattern = rules.decimal ? new RegExp(`^${sign}\\d*(?:\\.\\d*)?$`) : new RegExp(`^${sign}\\d*$`);
    return pattern.test(next);
}

export function installNumericInputGuard(root: Document = document): () => void {
    const onBeforeInput = (event: Event) => {
        const input = event.target;
        const data = (event as InputEvent).data;
        if (!(input instanceof HTMLInputElement) || data === null || data === undefined) return;
        const rules = numericRules(input);
        if (!rules) return;
        // Number inputs hide their selection, so check the inserted text itself; text inputs check the whole result.
        let next = data;
        if (input.type !== 'number' && input.selectionStart !== null && input.selectionEnd !== null) {
            next = input.value.slice(0, input.selectionStart) + data + input.value.slice(input.selectionEnd);
        } else if (input.type === 'number') {
            next = data;
            if (data.includes('.') && rules.decimal && input.value.includes('.')) next = 'x';
        }
        if (!acceptsNumeric(next.trim(), rules)) event.preventDefault();
    };
    const onKeyDown = (event: KeyboardEvent) => {
        // Chrome does not fire beforeinput for every rejected key in number fields; stop the classic offenders.
        const input = event.target;
        if (input instanceof HTMLInputElement && input.type === 'number' && ['e', 'E', '+'].includes(event.key)) event.preventDefault();
    };
    root.addEventListener('beforeinput', onBeforeInput, true);
    root.addEventListener('keydown', onKeyDown, true);
    return () => {
        root.removeEventListener('beforeinput', onBeforeInput, true);
        root.removeEventListener('keydown', onKeyDown, true);
    };
}
