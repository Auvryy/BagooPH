// Form previews only. Saved amounts and cash authority are checked by the server.
export function cashInputCents(value: string): number | null {
    if (!/^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,2})?$/.test(value)) return null;
    const [whole, decimal = ''] = value.split('.');
    return Number(whole) * 100 + Number(decimal.padEnd(2, '0'));
}

export function cashInputPesos(cents: number): string {
    return `${Math.floor(cents / 100)}.${String(cents % 100).padStart(2, '0')}`;
}
