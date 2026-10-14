export function companyTextError(value: string, minimum: number, maximum: number): string | undefined {
    if (Array.from(value).length > maximum) return `Use no more than ${maximum} characters.`;
    if (/^[ \r\n]*$/.test(value)) return undefined;
    const readable = (text: string) => !/[\p{C}<>`{}]/u.test(text.replace(/\r?\n/g, ''))
        && !/[\u034f\ufe00-\ufe0f]|(?:javascript|vbscript|data)\s*:|\bon[a-z]+\s*=/iu.test(text)
        && /[\p{L}\p{N}]/u.test(text);
    if (!readable(value)) return 'Use readable plain text without markup or hidden characters.';
    const normalized = value.normalize('NFKC').replace(/^[ \r\n]+|[ \r\n]+$/g, '');
    if (!readable(normalized)) return 'Use readable plain text without markup or hidden characters.';
    const length = Array.from(normalized).length;
    return length < minimum || length > maximum ? `Use ${minimum}–${maximum} characters, or leave this blank.` : undefined;
}

export function companyAreas(areas: { city: string | null; province: string | null }[]): string {
    const places = [...new Set(areas.map(({ city, province }) => [city, province].filter(Boolean).join(', ')).filter(Boolean))];
    return places.length ? places.join(' · ') : 'Service locality not recorded';
}
