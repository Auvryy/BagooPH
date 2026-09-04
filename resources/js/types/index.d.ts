export type Role = 'admin' | 'seller' | 'buyer' | 'courier' | 'logistics';

export type KycStatus = 'pending_approval' | 'approved' | 'rejected' | 'none';

export interface CourierProfile {
    id: number;
    user_id: number;
    vehicle_type: string;
    plate_number?: string | null;
    license_number?: string | null;
    or_cr_status?: string | null;
    is_available: boolean;
    created_at?: string;
    updated_at?: string;
}

export interface User {
    id: number;
    name: string;
    email: string;
    role: Role;
    phone?: string | null;
    avatar?: string | null;
    address?: string | null;
    city?: string | null;
    postal_code?: string | null;
    status?: string;
    kyc_status?: KycStatus;
    id_document_path?: string | null;
    business_permit_path?: string | null;
    driver_license_path?: string | null;
    or_cr_path?: string | null;
    kyc_feedback?: string | null;
    kyc_submitted_at?: string | null;
    kyc_reviewed_at?: string | null;
    email_verified_at?: string;
    shop?: Shop | null;
    courier_profile?: CourierProfile | null;
}

export interface Shop {
    id: number;
    user_id: number;
    name: string;
    slug: string;
    description?: string | null;
    logo?: string | null;
    banner?: string | null;
    phone?: string | null;
    address?: string | null;
    city?: string | null;
    rating: string | number;
    status: string;
    products_count?: number;
    user?: User;
}

export interface Category {
    id: number;
    parent_id?: number | null;
    name: string;
    slug: string;
    icon?: string | null;
    image?: string | null;
    description?: string | null;
    is_active: boolean;
    products_count?: number;
}

export interface ProductImage {
    id: number;
    product_id: number;
    image_url: string;
    is_primary: boolean;
    sort_order: number;
}

export interface Product {
    id: number;
    shop_id: number;
    category_id?: number | null;
    name: string;
    slug: string;
    description: string;
    price: string | number;
    compare_at_price?: string | number | null;
    stock: number;
    sku?: string | null;
    variants?: {
        colors?: { id: string; name: string; hex: string; in_stock?: boolean }[];
        sizes?: { id: string; name: string; extra_price: number; stock?: number }[];
    } | null;
    featured_image?: string | null;
    weight_kg?: string | number;
    status: 'active' | 'draft' | 'archived';
    rating: string | number;
    sales_count: number;
    shop?: Shop;
    category?: Category;
    images?: ProductImage[];
    reviews?: Review[];
    created_at?: string;
}

export interface CartItem {
    id: number;
    cart_id: number;
    product_id: number;
    quantity: number;
    unit_price: string | number;
    color?: string | null;
    size?: string | null;
    sku_snapshot?: string | null;
    product: Product;
}

export interface Cart {
    id: number;
    user_id?: number | null;
    session_id?: string | null;
    items: CartItem[];
    total?: number;
}

export interface OrderItem {
    id: number;
    order_id: number;
    product_id: number;
    shop_id: number;
    quantity: number;
    unit_price: string | number;
    subtotal: string | number;
    color?: string | null;
    size?: string | null;
    sku_snapshot?: string | null;
    product: Product;
    shop?: Shop;
    order?: Order;
}

export interface Delivery {
    id: number;
    order_id: number;
    courier_id?: number | null;
    tracking_number: string;
    logistics_partner: string;
    status: 'unassigned' | 'assigned' | 'assigned_pickup' | 'picked_up' | 'at_sorting_center' | 'sorted' | 'assigned_to_rider' | 'in_transit' | 'out_for_delivery' | 'delivered' | 'failed' | 'delivery_failed' | 'returned' | 'cancelled';
    pickup_store_name?: string | null;
    pickup_address: string;
    pickup_phone?: string | null;
    delivery_address: string;
    delivery_recipient_name: string;
    delivery_phone: string;
    estimated_delivery_at?: string | null;
    assigned_at?: string | null;
    picked_up_at?: string | null;
    delivered_at?: string | null;
    proof_image?: string | null;
    courier_notes?: string | null;
    courier?: User | null;
    order?: Order;
}

export interface Order {
    id: number;
    order_number: string;
    buyer_id: number;
    subtotal: string | number;
    shipping_fee: string | number;
    total_amount: string | number;
    payment_method: 'card' | 'cod' | 'bank_transfer' | 'e_wallet';
    payment_status: 'pending' | 'paid' | 'failed' | 'refunded';
    status: 'placed' | 'confirmed' | 'preparing' | 'ready_for_pickup' | 'picked_up' | 'at_sorting_center' | 'sorted' | 'assigned_to_rider' | 'out_for_delivery' | 'delivered' | 'completed' | 'delivery_failed' | 'returned' | 'pending' | 'processing' | 'shipped' | 'cancelled';
    recipient_name: string;
    recipient_phone: string;
    shipping_address: string;
    shipping_city: string;
    shipping_postal_code?: string | null;
    notes?: string | null;
    buyer?: User;
    items?: OrderItem[];
    delivery?: Delivery | null;
    created_at: string;
}

export interface Review {
    id: number;
    product_id: number;
    buyer_id: number;
    rating: number;
    comment?: string | null;
    images?: string[] | null;
    buyer?: User;
    created_at?: string;
}

export interface PaginatedData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    next_page_url?: string | null;
    prev_page_url?: string | null;
    links?: { url: string | null; label: string; active: boolean }[];
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User | null;
    };
    cartCount: number;
    flash: {
        success?: string | null;
        error?: string | null;
        message?: string | null;
    };
};
