export type CorrectionValue = string | number | string[] | null;

/** PHP multipart keys must preserve list values for reviewed service regions. */
export function appendCorrectionChanges(body: FormData, changes: Record<string, CorrectionValue>): void {
    Object.entries(changes).forEach(([key, value]) => {
        if (Array.isArray(value)) {
            value.forEach((item, index) => body.append(`changes[${key}][${index}]`, item));
        } else {
            body.append(`changes[${key}]`, String(value ?? ''));
        }
    });
}

export function correctionValueLabel(value: CorrectionValue): string | number {
    if (Array.isArray(value)) return value.length ? value.join(', ') : 'None selected';
    return value ?? 'Not recorded';
}
