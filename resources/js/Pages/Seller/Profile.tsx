import React, { useState, useRef, useEffect } from 'react';
import { Head, useForm, Link } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import PhoneInput from '@/Components/PhoneInput';
import { User, Shop } from '@/types';
import { 
    User as UserIcon, 
    Mail, 
    Phone, 
    Camera, 
    Upload, 
    Trash2, 
    Check, 
    Store, 
    Settings, 
    ExternalLink, 
    ShieldCheck, 
    AlertCircle, 
    ArrowRight 
} from 'lucide-react';

interface Props {
    user: User;
    shop?: Shop | null;
}

interface ProfileFormData {
    name: string;
    email: string;
    phone: string;
    avatar: File | null;
    remove_avatar: boolean;
}

export default function SellerProfile({ user, shop }: Props) {
    const fileInputRef = useRef<HTMLInputElement | null>(null);
    const [avatarPreview, setAvatarPreview] = useState<string | null>(user.avatar || null);
    const [fileValidationError, setFileValidationError] = useState<string | null>(null);

    const { data, setData, post, processing, errors, recentlySuccessful } = useForm<ProfileFormData>({
        name: user.name || '',
        email: user.email || '',
        phone: user.phone || '',
        avatar: null,
        remove_avatar: false,
    });

    useEffect(() => {
        setAvatarPreview(user.avatar || null);
    }, [user.avatar]);

    const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        setFileValidationError(null);
        const file = e.target.files?.[0];
        if (!file) return;

        if (file.size > 3 * 1024 * 1024) {
            setFileValidationError('Image file size must be less than 3MB.');
            return;
        }

        const validTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp', 'image/gif'];
        if (!validTypes.includes(file.type)) {
            setFileValidationError('Please select a valid image file (JPEG, PNG, WEBP, or GIF).');
            return;
        }

        const reader = new FileReader();
        reader.onload = () => {
            setAvatarPreview(reader.result as string);
        };
        reader.readAsDataURL(file);

        setData(prev => ({
            ...prev,
            avatar: file,
            remove_avatar: false,
        }));
    };

    const handleRemoveAvatar = () => {
        setFileValidationError(null);
        setAvatarPreview(null);
        setData(prev => ({
            ...prev,
            avatar: null,
            remove_avatar: true,
        }));
        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setFileValidationError(null);
        post(route('seller.profile.update'), {
            forceFormData: true,
            preserveScroll: true,
        });
    };

    return (
        <DashboardLayout
            title="Profile"
            subtitle="Manage your personal merchant identity and credentials"
            actions={
                <div className="flex items-center gap-2.5">
                    <Link
                        href={route('seller.settings')}
                        className="flex items-center gap-1.5 px-3.5 py-2 bg-white hover:bg-slate-100 text-slate-700 text-xs font-bold font-mono rounded-xl border border-slate-200 transition shadow-2xs"
                    >
                        <Settings className="w-3.5 h-3.5 text-slate-500" />
                        <span>Store Settings</span>
                    </Link>
                    {shop?.slug && (
                        <a
                            href={route('seller.preview')}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="flex items-center gap-1.5 px-3.5 py-2 bg-[#E00D42] hover:bg-[#C20836] text-white text-xs font-bold font-mono rounded-xl transition shadow-xs"
                        >
                            <ExternalLink className="w-3.5 h-3.5" />
                            <span>Storefront Preview</span>
                        </a>
                    )}
                </div>
            }
        >
            <Head title="Merchant Profile — BagooPH Seller" />

            <div className="max-w-4xl space-y-6 font-sans">
                
                {/* Unified Settings Navigation Tabs */}
                <div className="flex items-center gap-2 border-b border-slate-200 pb-3 font-mono text-xs">
                    <Link
                        href={route('seller.settings')}
                        className="px-4 py-2 rounded-xl font-bold transition flex items-center gap-2 bg-white text-slate-600 hover:bg-slate-100 border border-slate-200"
                    >
                        <Store className="w-3.5 h-3.5 text-slate-400" />
                        <span>Store Details & Branding</span>
                    </Link>
                    <Link
                        href={route('seller.profile')}
                        className="px-4 py-2 rounded-xl font-bold transition flex items-center gap-2 bg-slate-900 text-white shadow-xs"
                    >
                        <UserIcon className="w-3.5 h-3.5" />
                        <span>Merchant Account & Profile</span>
                    </Link>
                </div>

                {/* Verification Status Banner */}
                <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col sm:flex-row sm:items-center justify-between gap-4 font-mono text-xs">
                    <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-700 flex items-center justify-center font-bold shrink-0">
                            <ShieldCheck className="w-5 h-5" />
                        </div>
                        <div>
                            <p className="font-bold text-slate-900 uppercase">
                                Verified Seller Account
                            </p>
                            <p className="text-slate-500 text-[11px] font-sans">
                                Active merchant role on BagooPH marketplace
                            </p>
                        </div>
                    </div>
                    <div className="flex items-center gap-3">
                        <span className="px-3 py-1 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full font-bold uppercase text-[10px]">
                            {user.status || 'Active'}
                        </span>
                    </div>
                </div>

                {/* Profile Form */}
                <form onSubmit={handleSubmit} className="bg-white rounded-2xl p-6 sm:p-8 border border-slate-200/90 shadow-2xs space-y-8">
                    <input
                        ref={fileInputRef}
                        type="file"
                        accept="image/jpeg,image/png,image/jpg,image/webp,image/gif"
                        onChange={handleFileChange}
                        className="hidden"
                    />

                    {/* Section: Profile Avatar */}
                    <div className="space-y-4">
                        <div>
                            <h3 className="text-sm font-bold text-slate-900 font-mono uppercase tracking-wider">
                                Merchant Avatar
                            </h3>
                            <p className="text-xs text-slate-500 mt-0.5">
                                This photo appears in your merchant cockpit, customer chats, and official seller communication.
                            </p>
                        </div>

                        <div className="p-5 bg-slate-50 rounded-2xl border border-slate-200/80 flex flex-col sm:flex-row sm:items-center gap-6">
                            {/* Avatar Live Preview */}
                            <div className="relative group shrink-0 w-24 h-24 rounded-2xl overflow-hidden border-2 border-slate-200 shadow-xs bg-white">
                                {avatarPreview ? (
                                    <img
                                        src={avatarPreview}
                                        alt={user.name}
                                        className="w-full h-full object-cover"
                                    />
                                ) : (
                                    <div className="w-full h-full bg-slate-950 text-white font-mono font-bold text-3xl flex items-center justify-center">
                                        {user.name ? user.name.charAt(0).toUpperCase() : 'M'}
                                    </div>
                                )}
                                <button
                                    type="button"
                                    onClick={() => fileInputRef.current?.click()}
                                    className="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 flex flex-col items-center justify-center text-white transition-opacity text-[10px] font-mono font-bold cursor-pointer"
                                >
                                    <Camera className="w-5 h-5 mb-1" />
                                    <span>Change</span>
                                </button>
                            </div>

                            {/* Actions and info */}
                            <div className="space-y-2.5 flex-1">
                                <div className="flex flex-wrap items-center gap-2.5">
                                    <button
                                        type="button"
                                        onClick={() => fileInputRef.current?.click()}
                                        className="px-4 py-2 bg-slate-900 hover:bg-slate-800 active:scale-[0.98] text-white rounded-xl text-xs font-mono font-bold uppercase transition flex items-center gap-1.5 shadow-xs cursor-pointer"
                                    >
                                        <Upload className="w-3.5 h-3.5" />
                                        <span>{avatarPreview ? 'Upload / Change Photo' : 'Upload Photo'}</span>
                                    </button>

                                    {avatarPreview && (
                                        <button
                                            type="button"
                                            onClick={handleRemoveAvatar}
                                            className="px-4 py-2 border border-slate-300 hover:border-rose-300 hover:bg-rose-50 text-slate-700 hover:text-rose-600 rounded-xl text-xs font-mono font-bold uppercase transition flex items-center gap-1.5 cursor-pointer"
                                        >
                                            <Trash2 className="w-3.5 h-3.5" />
                                            <span>Remove Photo</span>
                                        </button>
                                    )}
                                </div>

                                {avatarPreview !== user.avatar && (
                                    <p className="text-[11px] text-amber-700 font-mono font-medium">
                                        New photo selected. Click Save Changes below to apply.
                                    </p>
                                )}

                                <p className="text-[11px] text-slate-500 font-mono">
                                    Supported formats: JPEG, PNG, WEBP, GIF. Maximum file size: 3MB.
                                </p>

                                {(fileValidationError || errors.avatar) && (
                                    <p className="text-xs text-rose-600 font-mono font-bold flex items-center gap-1">
                                        <AlertCircle className="w-3.5 h-3.5 shrink-0" />
                                        <span>{fileValidationError || errors.avatar}</span>
                                    </p>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Section: Merchant Information */}
                    <div className="space-y-4 pt-4 border-t border-slate-100">
                        <div>
                            <h3 className="text-sm font-bold text-slate-900 font-mono uppercase tracking-wider">
                                Merchant Information
                            </h3>
                            <p className="text-xs text-slate-500 mt-0.5">
                                Primary contact and identity details associated with your merchant account.
                            </p>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <div className="sm:col-span-2">
                                <label className="block text-xs font-mono font-bold text-slate-700 uppercase mb-1.5">
                                    Full Name <span className="text-rose-500">*</span>
                                </label>
                                <div className="relative">
                                    <input
                                        type="text"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        className="w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 text-xs focus:bg-white focus:ring-1 focus:ring-[#E00D42] focus:border-[#E00D42] transition font-sans"
                                        placeholder="e.g. Maria Santos"
                                        required
                                    />
                                    <UserIcon className="w-4 h-4 text-slate-400 absolute left-3.5 top-3" />
                                </div>
                                {errors.name && <p className="text-xs text-rose-500 mt-1 font-mono">{errors.name}</p>}
                            </div>

                            <div>
                                <label className="block text-xs font-mono font-bold text-slate-700 uppercase mb-1.5">
                                    Email Address <span className="text-rose-500">*</span>
                                </label>
                                <div className="relative">
                                    <input
                                        type="email"
                                        value={data.email}
                                        onChange={(e) => setData('email', e.target.value)}
                                        className="w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 text-xs focus:bg-white focus:ring-1 focus:ring-[#E00D42] focus:border-[#E00D42] transition font-sans"
                                        placeholder="seller@example.com"
                                        required
                                    />
                                    <Mail className="w-4 h-4 text-slate-400 absolute left-3.5 top-3" />
                                </div>
                                {errors.email && <p className="text-xs text-rose-500 mt-1 font-mono">{errors.email}</p>}
                            </div>

                            <div>
                                <label className="block text-xs font-mono font-bold text-slate-700 uppercase mb-1.5">
                                    Mobile / Phone
                                </label>
                                <PhoneInput
                                    value={data.phone}
                                    onChange={(val) => setData('phone', val)}
                                    placeholder="917 123 4567"
                                    accentColor="primary"
                                    helperText="10-digit mobile number (e.g. 917 123 4567)"
                                />
                                {errors.phone && <p className="text-xs text-rose-500 mt-1 font-mono">{errors.phone}</p>}
                            </div>
                        </div>
                    </div>

                    {recentlySuccessful && (
                        <div className="p-3 bg-emerald-50 text-emerald-800 rounded-xl border border-emerald-200 text-xs font-mono flex items-center gap-2">
                            <Check className="w-4 h-4 text-emerald-600 shrink-0" />
                            <span>Profile details updated successfully!</span>
                        </div>
                    )}

                    <div className="flex items-center justify-end pt-4 border-t border-slate-100">
                        <button
                            type="submit"
                            disabled={processing}
                            className="px-6 py-2.5 rounded-xl bg-[#E00D42] hover:bg-[#C20836] text-white text-xs font-mono font-bold uppercase shadow-sm transition disabled:opacity-50 flex items-center gap-2 cursor-pointer"
                        >
                            {processing ? 'Saving...' : 'Save Changes'}
                        </button>
                    </div>
                </form>

                {/* Storefront Integration & Settings Link */}
                <div className="bg-white rounded-2xl p-6 border border-slate-200/90 shadow-2xs space-y-4">
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-3">
                            <div className="w-9 h-9 rounded-xl bg-slate-100 text-slate-700 flex items-center justify-center font-bold shrink-0">
                                <Store className="w-4 h-4" />
                            </div>
                            <div>
                                <h4 className="text-xs font-mono font-bold text-slate-900 uppercase">
                                    Storefront Management
                                </h4>
                                <p className="text-[11px] text-slate-500 font-sans">
                                    Configure store branding, warehouse dispatch address, and logos.
                                </p>
                            </div>
                        </div>
                        <Link
                            href={route('seller.settings')}
                            className="flex items-center gap-1.5 text-xs font-mono font-bold text-[#E00D42] hover:text-[#C20836] transition"
                        >
                            <span>Store Settings</span>
                            <ArrowRight className="w-3.5 h-3.5" />
                        </Link>
                    </div>
                    {shop && (
                        <div className="p-3.5 rounded-xl bg-slate-50 border border-slate-200/80 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs font-mono">
                            <div>
                                <span className="text-slate-500">Connected Store: </span>
                                <span className="font-bold text-slate-900">{shop.name}</span>
                                <span className="text-slate-400 ml-2 text-[11px]">(/shop/{shop.slug})</span>
                            </div>
                            <a
                                href={route('shop.show', shop.slug)}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="flex items-center gap-1 text-slate-600 hover:text-slate-900 transition underline underline-offset-2 text-[11px]"
                            >
                                <span>Preview Public Storefront</span>
                                <ExternalLink className="w-3 h-3" />
                            </a>
                        </div>
                    )}
                </div>

            </div>
        </DashboardLayout>
    );
}
