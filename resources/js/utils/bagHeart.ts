export type BagHeartAction = { type: 'open'; itemId: number } | { type: 'add' };

export function bagHeartAction(cartItemId?: number): BagHeartAction {
    return cartItemId ? { type: 'open', itemId: cartItemId } : { type: 'add' };
}
