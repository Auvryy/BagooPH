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
    wallet_refusal_refund: 'Refused parcel refund', wallet_refusal_shipping_payment: 'Reviewed shipping payment',
    wallet_loss_refund: 'Reviewed loss settlement', wallet_loss_funding: 'Reviewed loss reserve funding',
    demo_loss_funding_credit: 'Demo loss funding credits', wallet_loss_advance_retirement: 'Demo loss advance returned',
    demo_loss_credit_return: 'Unused demo loss credits returned',
    wallet_loss_company_source_return: 'Unused loss contribution restored',
    wallet_loss_platform_source_return: 'Unused platform loss reserve returned',
    wallet_loss_buyer_compensation_return: 'Voluntary buyer loss refund returned',
    wallet_loss_seller_compensation_return: 'Voluntary seller protection returned',
    wallet_loss_company_reimbursement: 'Company loss funding reimbursed',
    wallet_loss_escrow_reimbursement: 'Escrow loss compensation surplus restored',
}[kind] ?? 'Wallet transaction');
