import { manilaInput } from '@/types/advertisements';

type OfferPeriod = { starts_at: string; ends_at: string };
const minute = 60_000;
const validInput = (value: string) => /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(value)
    && Number.isFinite(new Date(`${value}:00Z`).getTime()) && new Date(`${value}:00Z`).toISOString().slice(0, 16) === value;
export const nextPromotionMinute = (value: string) => validInput(value) ? new Date(new Date(`${value}:00Z`).getTime() + minute).toISOString().slice(0, 16) : '';

export function promotionDateWindow(offer?: OfferPeriod, now = new Date()) {
    const today = `${manilaInput(now.toISOString()).slice(0, 10)}T00:00`;
    const offerStart = offer ? manilaInput(new Date(Math.ceil(new Date(offer.starts_at).getTime() / minute) * minute).toISOString()) : '';
    const maximum = offer ? manilaInput(offer.ends_at) : '';
    return { minimum: offerStart > today ? offerStart : today, maximum };
}

export function promotionSuggestedStart(offer?: OfferPeriod, now = new Date()) {
    const currentMinute = manilaInput(new Date(Math.ceil(now.getTime() / minute) * minute).toISOString());
    const { minimum } = promotionDateWindow(offer, now);
    return currentMinute > minimum ? currentMinute : minimum;
}

export function promotionDateErrors(start: string, end: string, offer?: OfferPeriod, savedStart?: string, now = new Date()) {
    const { minimum, maximum } = promotionDateWindow(offer, now);
    const errors: { starts_at?: string; ends_at?: string } = {};
    if (!start) errors.starts_at = 'Choose the promotion start date and time.';
    else if (!validInput(start)) errors.starts_at = 'Enter a valid start date and time.';
    else if (start < minimum && !(start === savedStart && (!offer || start >= manilaInput(offer.starts_at)))) errors.starts_at = 'Choose today or a future date within the linked voucher period.';
    if (!end) errors.ends_at = 'Choose the promotion end date and time.';
    else if (!validInput(end)) errors.ends_at = 'Enter a valid end date and time.';
    else if (end <= manilaInput(now.toISOString())) errors.ends_at = 'Choose a future end date and time in Asia/Manila.';
    else if (end <= start) errors.ends_at = 'Choose an end date and time after the start.';
    else if (maximum && end > maximum) errors.ends_at = 'End the promotion before or when the linked voucher ends.';
    if (maximum && start >= maximum) errors.starts_at = 'Start the promotion before the linked voucher ends.';
    return errors;
}
