import { User } from '@/types';

/**
 * KYC approval is the source of truth for buyer purchasing access. The
 * document path is deliberately not checked here because approved accounts
 * can have documents stored outside the public profile payload.
 */
export function isBuyerKycApproved(user: User | null | undefined): boolean {
    return user?.role === 'buyer'
        ? user.kyc_status === 'approved' || user.kyc_status === 'verified'
        : true;
}

export function buyerNeedsKycForPurchase(user: User | null | undefined): boolean {
    return Boolean(user?.role === 'buyer' && !isBuyerKycApproved(user));
}

export function buyerShouldSeeIdReminder(user: User | null | undefined): boolean {
    if (!user || user.role !== 'buyer' || isBuyerKycApproved(user)) {
        return false;
    }

    return user.kyc_status === 'none'
        || user.kyc_status === 'rejected'
        || !user.kyc_status;
}

export function buyerKycBlockMessage(user: User | null | undefined): string {
    if (user?.kyc_status === 'pending_approval') {
        return 'Your ID is still under review. You can keep items in your bag, but purchasing stays locked until approval.';
    }

    if (user?.kyc_status === 'rejected') {
        return 'Your previous ID was rejected. Upload a new valid ID from your profile before purchasing.';
    }

    return 'Verify your identity with a valid government ID before purchasing. You can still add this item to your bag.';
}
