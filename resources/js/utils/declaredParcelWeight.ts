export function declaredParcelWeight(items: { quantity: number; declared_weight_kg?: string | number | null }[]): string | null {
    if (!items.length || items.some(item => item.declared_weight_kg == null || !Number.isFinite(Number(item.declared_weight_kg)) || Number(item.declared_weight_kg) <= 0 || !Number.isInteger(item.quantity) || item.quantity < 1)) return null;
    const hundredths = items.reduce((total, item) => total + Math.round(Number(item.declared_weight_kg) * 100) * item.quantity, 0);
    return (hundredths / 100).toFixed(2);
}
