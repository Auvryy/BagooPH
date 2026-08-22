import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

export default function Register() {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        role: 'buyer' as 'buyer' | 'seller' | 'courier',
        shop_name: '',
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('register'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <GuestLayout>
            <Head title="Create Account — Bagoo" />

            <div className="mb-6 text-center">
                <h2 className="text-xl font-black text-slate-900">Join Bagoo Ecosystem</h2>
                <p className="text-xs text-slate-500 mt-1">Select your account type to get started</p>
            </div>

            <form onSubmit={submit} className="space-y-4">
                {/* Role selection radio grid */}
                <div>
                    <InputLabel value="I want to:" />
                    <div className="grid grid-cols-3 gap-2 mt-1.5">
                        {[
                            { id: 'buyer', label: 'Shop (Buyer)' },
                            { id: 'seller', label: 'Sell (Merchant)' },
                            { id: 'courier', label: 'Deliver (Courier)' },
                        ].map((r) => (
                            <button
                                key={r.id}
                                type="button"
                                onClick={() => setData('role', r.id as any)}
                                className={`p-2.5 rounded-xl border text-center font-bold text-xs transition ${
                                    data.role === r.id
                                        ? 'border-indigo-600 bg-indigo-50 text-indigo-700 shadow-xs'
                                        : 'border-slate-200 bg-slate-50 text-slate-600 hover:bg-slate-100'
                                }`}
                            >
                                {r.label}
                            </button>
                        ))}
                    </div>
                </div>

                {data.role === 'seller' && (
                    <div>
                        <InputLabel htmlFor="shop_name" value="Store / Merchant Business Name" />
                        <TextInput
                            id="shop_name"
                            name="shop_name"
                            value={data.shop_name}
                            className="mt-1 block w-full text-xs"
                            placeholder="e.g. Apex Bags & Apparel"
                            onChange={(e) => setData('shop_name', e.target.value)}
                            required
                        />
                        <InputError message={errors.shop_name} className="mt-1" />
                    </div>
                )}

                <div>
                    <InputLabel htmlFor="name" value="Your Full Name" />

                    <TextInput
                        id="name"
                        name="name"
                        value={data.name}
                        className="mt-1 block w-full text-xs"
                        autoComplete="name"
                        isFocused={true}
                        onChange={(e) => setData('name', e.target.value)}
                        required
                    />

                    <InputError message={errors.name} className="mt-1" />
                </div>

                <div>
                    <InputLabel htmlFor="email" value="Email Address" />

                    <TextInput
                        id="email"
                        type="email"
                        name="email"
                        value={data.email}
                        className="mt-1 block w-full text-xs"
                        autoComplete="username"
                        onChange={(e) => setData('email', e.target.value)}
                        required
                    />

                    <InputError message={errors.email} className="mt-1" />
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <InputLabel htmlFor="password" value="Password" />

                        <TextInput
                            id="password"
                            type="password"
                            name="password"
                            value={data.password}
                            className="mt-1 block w-full text-xs"
                            autoComplete="new-password"
                            onChange={(e) => setData('password', e.target.value)}
                            required
                        />

                        <InputError message={errors.password} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel
                            htmlFor="password_confirmation"
                            value="Confirm Password"
                        />

                        <TextInput
                            id="password_confirmation"
                            type="password"
                            name="password_confirmation"
                            value={data.password_confirmation}
                            className="mt-1 block w-full text-xs"
                            autoComplete="new-password"
                            onChange={(e) =>
                                setData('password_confirmation', e.target.value)
                            }
                            required
                        />

                        <InputError
                            message={errors.password_confirmation}
                            className="mt-1"
                        />
                    </div>
                </div>

                <div className="pt-2">
                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs rounded-xl shadow-md shadow-indigo-600/20 transition active:scale-95 disabled:opacity-50"
                    >
                        Create My Account
                    </button>
                </div>

                <div className="text-center pt-2 text-xs text-slate-500">
                    Already have an account?{' '}
                    <Link href={route('login')} className="text-indigo-600 font-bold hover:underline">
                        Sign In
                    </Link>
                </div>
            </form>
        </GuestLayout>
    );
}
