import React, { useState, useMemo } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import BuyerLayout from '@/Layouts/BuyerLayout';
import { Cart, CartItem } from '@/types';
import { 
    ShoppingBag, 
    Trash2, 
    Plus, 
    Minus, 
    ArrowRight, 
    ShieldCheck, 
    Truck, 
    Store,
    ArrowLeft,
    Sparkles,
    Filter,
    ArrowUpDown,
    Search
} from 'lucide-react';

interface Props {
    cart: Cart;
    items: CartItem[];
    total: number;
}

export default function CartIndex({ cart, items, total }: Props) {
    // Determine the most recently added or updated product in the bag
    const getMostRecentItemId = (itemList: CartItem[]): number | null => {
        if (!itemList || itemList.length === 0) return null;
        const sorted = [...itemList].sort((a, b) => {
            const timeA = new Date(a.updated_at || a.created_at || 0).getTime();
            const timeB = new Date(b.updated_at || b.created_at || 0).getTime();
            if (timeB !== timeA) return timeB - timeA;
            return b.id - a.id;
        });
        return sorted[0]?.id ?? null;
    };

    const mostRecentId = getMostRecentItemId(items);

    // Default to only checking the recent product added when opening the cart
    const [selectedIds, setSelectedIds] = useState<number[]>(() => {
        return mostRecentId ? [mostRecentId] : [];
    });

    type SortOption = 'recent' | 'oldest' | 'price_low' | 'price_high' | 'name_asc';
    type FilterOption = 'all' | 'selected' | 'unselected';

    const [sortBy, setSortBy] = useState<SortOption>('recent');
    const [filterBy, setFilterBy] = useState<FilterOption>('all');
    const [searchQuery, setSearchQuery] = useState<string>('');

    // Process displayed items according to search, filter and sort options
    const displayedItems = useMemo(() => {
        let result = [...items];

        // 1. Search filter
        if (searchQuery.trim()) {
            const q = searchQuery.toLowerCase();
            result = result.filter(item => 
                item.product?.name?.toLowerCase().includes(q) ||
                item.color?.toLowerCase().includes(q) ||
                item.size?.toLowerCase().includes(q)
            );
        }

        // 2. Selection filter
        if (filterBy === 'selected') {
            result = result.filter(item => selectedIds.includes(item.id));
        } else if (filterBy === 'unselected') {
            result = result.filter(item => !selectedIds.includes(item.id));
        }

        // 3. Sorting (Default: 'recent' where the recent product is the first row)
        result.sort((a, b) => {
            if (sortBy === 'recent') {
                const timeA = new Date(a.updated_at || a.created_at || 0).getTime();
                const timeB = new Date(b.updated_at || b.created_at || 0).getTime();
                if (timeB !== timeA) return timeB - timeA;
                return b.id - a.id;
            } else if (sortBy === 'oldest') {
                const timeA = new Date(a.updated_at || a.created_at || 0).getTime();
                const timeB = new Date(b.updated_at || b.created_at || 0).getTime();
                if (timeA !== timeB) return timeA - timeB;
                return a.id - b.id;
            } else if (sortBy === 'price_low') {
                return Number(a.unit_price) - Number(b.unit_price);
            } else if (sortBy === 'price_high') {
                return Number(b.unit_price) - Number(a.unit_price);
            } else if (sortBy === 'name_asc') {
                return (a.product?.name || '').localeCompare(b.product?.name || '');
            }
            return 0;
        });

        return result;
    }, [items, searchQuery, filterBy, sortBy, selectedIds]);

    const toggleItemSelection = (id: number) => {
        setSelectedIds(prev =>
            prev.includes(id) ? prev.filter(x => x !== id) : [...prev, id]
        );
    };

    const toggleSelectAll = () => {
        if (selectedIds.length === items.length) {
            setSelectedIds([]);
        } else {
            setSelectedIds(items.map(i => i.id));
        }
    };

    const updateQuantity = (item: CartItem, newQty: number) => {
        if (newQty < 1) return;
        router.patch(route('cart.update', item.id), { quantity: newQty }, { preserveScroll: true });
    };

    const removeItem = (item: CartItem) => {
        setSelectedIds(prev => prev.filter(id => id !== item.id));
        router.delete(route('cart.destroy', item.id), { preserveScroll: true });
    };

    const selectedItems = items.filter(item => selectedIds.includes(item.id));
    const subtotal = selectedItems.reduce((sum, item) => sum + (Number(item.unit_price) * item.quantity), 0);
    const shipping = selectedItems.length === 0 ? 0 : (subtotal > 1500 ? 0 : (subtotal > 0 ? 50 : 0));
    const grandTotal = Math.max(0, subtotal + shipping);

    const formatPrice = (amount?: number | string | null) => {
        const numeric = Number(amount || 0);
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
            minimumFractionDigits: 2,
        }).format(numeric);
    };

    return (
        <BuyerLayout>
            <Head title="My Shopping Bag — BagooPH" />

            <div className="space-y-6">
                
                {/* Header */}
                <div className="flex items-center justify-between pb-4 border-b border-slate-200">
                    <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-xl bg-rose-50 text-[#E00D42] flex items-center justify-center font-bold">
                            <ShoppingBag className="w-5 h-5" />
                        </div>
                        <div>
                            <h1 className="text-xl sm:text-2xl font-black text-slate-900 tracking-tight">Shopping Bag</h1>
                            <p className="text-xs text-slate-500 font-mono">{items.length} items ready for doorstep dispatch</p>
                        </div>
                    </div>

                    <Link
                        href={route('buyer.index')}
                        className="text-xs font-bold text-[#E00D42] hover:underline flex items-center gap-1 font-mono uppercase"
                    >
                        <ArrowLeft className="w-4 h-4" />
                        <span>Continue Shopping</span>
                    </Link>
                </div>

                {items.length === 0 ? (
                    <div className="bg-white rounded-2xl border border-slate-200 p-12 text-center space-y-4 max-w-lg mx-auto shadow-xs">
                        <div className="w-16 h-16 rounded-2xl bg-slate-100 flex items-center justify-center mx-auto text-slate-400">
                            <ShoppingBag className="w-8 h-8" />
                        </div>
                        <h2 className="text-lg font-bold text-slate-800">Your shopping bag is empty</h2>
                        <p className="text-xs text-slate-500">Discover authentic products across 14 verified departments!</p>
                        <Link
                            href={route('buyer.index')}
                            className="inline-flex items-center gap-2 px-6 py-2.5 bg-[#E00D42] text-white text-xs font-bold rounded-xl uppercase tracking-wider shadow-sm hover:bg-[#C20836] transition"
                        >
                            <span>Browse 14 Departments</span>
                            <ArrowRight className="w-4 h-4" />
                        </Link>
                    </div>
                ) : (
                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
                        
                        {/* Cart Items List (Grouped by Shop) */}
                        <div className="lg:col-span-8 space-y-4">
                            {/* Filter & Sort Controls */}
                            <div className="bg-white rounded-2xl p-4 border border-slate-200 shadow-xs space-y-3 font-sans">
                                <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
                                    {/* Search Input in Cart */}
                                    <div className="relative flex-1">
                                        <Search className="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                                        <input
                                            type="text"
                                            value={searchQuery}
                                            onChange={(e) => setSearchQuery(e.target.value)}
                                            placeholder="Search items in your bag..."
                                            className="w-full pl-9 pr-7 py-2 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:ring-1 focus:ring-[#E00D42] focus:border-[#E00D42] transition font-sans"
                                        />
                                        {searchQuery && (
                                            <button
                                                type="button"
                                                onClick={() => setSearchQuery('')}
                                                className="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 text-xs"
                                            >
                                                &times;
                                            </button>
                                        )}
                                    </div>

                                    {/* Sort Dropdown */}
                                    <div className="flex items-center gap-2">
                                        <span className="text-xs text-slate-500 flex items-center gap-1 shrink-0 font-medium font-sans">
                                            <ArrowUpDown className="w-3.5 h-3.5 text-slate-400" />
                                            Sort:
                                        </span>
                                        <select
                                            value={sortBy}
                                            onChange={(e) => setSortBy(e.target.value as SortOption)}
                                            className="text-xs bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-slate-800 font-semibold focus:ring-1 focus:ring-[#E00D42] focus:border-[#E00D42] cursor-pointer font-sans"
                                        >
                                            <option value="recent">Recent Product First (Default)</option>
                                            <option value="oldest">Oldest Added</option>
                                            <option value="price_low">Price: Low to High</option>
                                            <option value="price_high">Price: High to Low</option>
                                            <option value="name_asc">Product Name (A-Z)</option>
                                        </select>
                                    </div>
                                </div>

                                {/* Filter Pills */}
                                <div className="flex items-center gap-2 pt-2 border-t border-slate-100 text-xs font-sans">
                                    <span className="text-slate-400 font-medium text-[11px] flex items-center gap-1 mr-1">
                                        <Filter className="w-3 h-3" />
                                        Filter:
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() => setFilterBy('all')}
                                        className={`px-2.5 py-1 rounded-lg font-semibold transition text-xs ${
                                            filterBy === 'all'
                                                ? 'bg-slate-900 text-white'
                                                : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                                        }`}
                                    >
                                        All ({items.length})
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setFilterBy('selected')}
                                        className={`px-2.5 py-1 rounded-lg font-semibold transition text-xs ${
                                            filterBy === 'selected'
                                                ? 'bg-[#E00D42] text-white'
                                                : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                                        }`}
                                    >
                                        Selected ({selectedIds.length})
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setFilterBy('unselected')}
                                        className={`px-2.5 py-1 rounded-lg font-semibold transition text-xs ${
                                            filterBy === 'unselected'
                                                ? 'bg-slate-700 text-white'
                                                : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                                        }`}
                                    >
                                        Unselected ({Math.max(0, items.length - selectedIds.length)})
                                    </button>
                                </div>
                            </div>

                            {/* Cart Items Container */}
                            <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs space-y-4">
                                <div className="flex items-center justify-between pb-3 border-b border-slate-100 text-xs">
                                    <label className="flex items-center gap-2.5 cursor-pointer select-none font-bold text-slate-800">
                                        <input
                                            type="checkbox"
                                            checked={items.length > 0 && selectedIds.length === items.length}
                                            onChange={toggleSelectAll}
                                            className="w-4 h-4 rounded text-[#E00D42] focus:ring-[#E00D42]/20 border-slate-300 cursor-pointer accent-[#E00D42]"
                                        />
                                        <span>Select All ({selectedIds.length}/{items.length} items)</span>
                                    </label>
                                    <div className="flex items-center gap-2">
                                        <Store className="w-4 h-4 text-[#E00D42]" />
                                        <span className="font-semibold text-slate-600">Bagoo Verified Merchants</span>
                                    </div>
                                </div>

                                {displayedItems.length === 0 ? (
                                    <div className="py-12 text-center space-y-2">
                                        <p className="text-xs text-slate-500 font-sans">No items match your filter criteria.</p>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setFilterBy('all');
                                                setSearchQuery('');
                                                setSortBy('recent');
                                            }}
                                            className="text-xs text-[#E00D42] font-bold hover:underline font-mono"
                                        >
                                            Reset Filters
                                        </button>
                                    </div>
                                ) : (
                                    <div className="divide-y divide-slate-100">
                                        {displayedItems.map((item) => (
                                            <div key={item.id} className="py-4 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                                                
                                                {/* Product Info with Checkbox */}
                                                <div className="flex items-center gap-3.5 min-w-0">
                                                    <input
                                                        type="checkbox"
                                                        checked={selectedIds.includes(item.id)}
                                                        onChange={() => toggleItemSelection(item.id)}
                                                        className="w-4 h-4 rounded text-[#E00D42] focus:ring-[#E00D42]/20 border-slate-300 cursor-pointer accent-[#E00D42] shrink-0"
                                                    />
                                                    <img
                                                        src={(item.color && item.product?.variants?.colors?.find(c => c.name === item.color)?.image_url) || item.product?.featured_image || ''}
                                                        alt={item.product?.name}
                                                        className="w-16 h-16 rounded-xl object-cover bg-slate-100 shrink-0 border border-slate-200"
                                                    />
                                                    <div className="truncate space-y-1">
                                                        <div className="flex items-center gap-2">
                                                            <Link 
                                                                href={route('buyer.products.show', item.product?.slug || '')}
                                                                className="font-bold text-sm text-slate-900 hover:text-[#E00D42] transition truncate block"
                                                            >
                                                                {item.product?.name}
                                                            </Link>
                                                            {item.id === mostRecentId && (
                                                                <span className="shrink-0 px-2 py-0.5 rounded-md bg-rose-50 border border-rose-200 text-[#E00D42] text-[10px] font-bold font-mono">
                                                                    Recent
                                                                </span>
                                                            )}
                                                        </div>
                                                        <div className="flex items-center gap-2 text-xs font-mono">
                                                            <span className="font-black text-[#E00D42]">
                                                                {formatPrice(item.unit_price)}
                                                            </span>
                                                            <span className="text-slate-400">•</span>
                                                            <span className="text-slate-500 text-[11px]">In Stock ({item.product?.stock ?? 45})</span>
                                                        </div>
                                                    </div>
                                                </div>

                                                {/* Quantity & Delete Controls */}
                                                <div className="flex items-center justify-between sm:justify-end w-full sm:w-auto gap-4 font-mono text-xs">
                                                    <div className="flex items-center border border-slate-300 rounded-lg overflow-hidden">
                                                        <button
                                                            type="button"
                                                            onClick={() => updateQuantity(item, item.quantity - 1)}
                                                            className="px-2.5 py-1 bg-slate-50 hover:bg-slate-100 text-slate-700 disabled:opacity-40"
                                                        >
                                                            <Minus className="w-3 h-3" />
                                                        </button>
                                                        <span className="px-3 py-1 font-bold text-slate-900">{item.quantity}</span>
                                                        <button
                                                            type="button"
                                                            onClick={() => updateQuantity(item, item.quantity + 1)}
                                                            className="px-2.5 py-1 bg-slate-50 hover:bg-slate-100 text-slate-700 disabled:opacity-40"
                                                        >
                                                            <Plus className="w-3 h-3" />
                                                        </button>
                                                    </div>

                                                    <span className="font-bold text-slate-900 min-w-[80px] text-right">
                                                        {formatPrice(Number(item.unit_price) * item.quantity)}
                                                    </span>

                                                    <button
                                                        type="button"
                                                        onClick={() => removeItem(item)}
                                                        className="p-1.5 text-slate-400 hover:text-rose-600 rounded-lg hover:bg-rose-50 transition"
                                                    >
                                                        <Trash2 className="w-4 h-4" />
                                                    </button>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Order Summary */}
                        <div className="lg:col-span-4 space-y-4">
                            {/* Summary Totals Card */}
                            <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs space-y-4 font-mono text-xs">
                                <h3 className="font-black text-slate-900 text-sm uppercase tracking-wider pb-3 border-b border-slate-100">
                                    Order Summary
                                </h3>

                                <div className="space-y-2 text-slate-600">
                                    <div className="flex justify-between">
                                        <span>Merchandise Subtotal ({selectedItems.length} selected):</span>
                                        <span className="font-bold text-slate-900">{formatPrice(subtotal)}</span>
                                    </div>

                                    <div className="flex justify-between">
                                        <span>Courier Shipping Fee:</span>
                                        <span className={shipping === 0 ? 'text-emerald-600 font-bold' : 'font-bold text-slate-900'}>
                                            {shipping === 0 ? 'FREE' : formatPrice(shipping)}
                                        </span>
                                    </div>
                                </div>

                                <div className="pt-3 border-t border-slate-100 flex justify-between items-baseline text-sm">
                                    <span className="font-bold text-slate-900">Total Payment:</span>
                                    <span className="text-xl font-black text-[#E00D42] font-sans">
                                        {formatPrice(grandTotal)}
                                    </span>
                                </div>

                                {selectedIds.length === 0 ? (
                                    <button
                                        type="button"
                                        disabled
                                        className="w-full py-3 bg-slate-100 border border-slate-200 text-slate-400 font-bold rounded-xl uppercase tracking-wider text-xs cursor-not-allowed text-center select-none"
                                    >
                                        Select Items to Checkout
                                    </button>
                                ) : (
                                    <Link
                                        href={route('checkout.index', { items: selectedIds.join(',') })}
                                        className="w-full py-3 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white font-bold rounded-xl uppercase tracking-wider transition shadow-md flex items-center justify-center gap-2 text-xs"
                                    >
                                        <span>Proceed to Checkout ({selectedIds.length})</span>
                                        <ArrowRight className="w-4 h-4" />
                                    </Link>
                                )}

                                <div className="flex items-center justify-center gap-2 text-[10px] text-slate-400 pt-2">
                                    <ShieldCheck className="w-3.5 h-3.5 text-emerald-500" />
                                    <span>100% Secure Checkout Guaranteed</span>
                                </div>
                            </div>
                        </div>
                    </div>
                )}

            </div>
        </BuyerLayout>
    );
}
