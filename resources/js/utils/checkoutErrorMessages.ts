export const deliveryLocationErrorMessage = 'Choose a valid delivery location on the map or use GPS.';

export function checkoutErrorMessages(errors: Record<string, string>): string[] {
    const hasDeliveryLocationError = Boolean(errors.shipping_latitude || errors.shipping_longitude);
    const messages = Object.entries(errors)
        .filter(([field]) => field !== 'shipping_latitude' && field !== 'shipping_longitude')
        .map(([, message]) => message);

    if (hasDeliveryLocationError) messages.push(deliveryLocationErrorMessage);

    return Array.from(new Set(messages));
}
