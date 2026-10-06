export function formatPhpDecimal(value: string | null): string {
    if (value === null || !/^-?\d+(?:\.\d{1,2})?$/.test(value)) return 'Unavailable';
    const negative = value.startsWith('-');
    const [whole, fraction = ''] = (negative ? value.slice(1) : value).split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return `${negative ? '-' : ''}₱${grouped}.${fraction.padEnd(2, '0')}`;
}
