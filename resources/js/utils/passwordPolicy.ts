export const passwordRequirements = 'Use 12–128 characters, including uppercase and lowercase letters, a number and a symbol.';

export function passwordError(value: string): string | undefined {
    const length = Array.from(value).length;
    if (length < 12 || length > 128) return passwordRequirements;
    if (/\p{C}/u.test(value) || !/\p{Lu}/u.test(value) || !/\p{Ll}/u.test(value) || !/\p{N}/u.test(value) || !/[\p{S}\p{P}]/u.test(value)) return passwordRequirements;
    return undefined;
}
