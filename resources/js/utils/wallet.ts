export const walletMoney = (cents: number) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(cents / 100);

export function creditAmountError(value: string): string | null {
    if (!/^(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{1,2})?$/.test(value)) return 'Enter a peso amount with up to two decimal places.';
    const [whole, fraction = ''] = value.split('.');
    const cents = Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
    return cents < 100 || cents > 10000000 ? 'Request between ₱1.00 and ₱100,000.00 in demo credits.' : null;
}

export const walletKind = (kind: string) => ({
    demo_credit: 'Demo credits added', wallet_payment: 'Order payment', wallet_cancellation_refund: 'Cancelled order refund',
    wallet_return_refund: 'Return refund', wallet_settlement: 'Virtual seller proceeds', wallet_delivery_refund: 'Returned parcel refund',
}[kind] ?? 'Wallet transaction');
