export function productQuantityError(value: string, maximum: number): string | null {
    if (!/^[1-9][0-9]?$/.test(value)) return 'Enter a whole-number quantity from 1 to 99.';
    if (maximum < 1) return 'This selection has no more available units to add.';
    if (Number(value) > maximum) return `You can add only ${maximum} more of this selection.`;
    return null;
}
