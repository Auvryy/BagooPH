import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

export default function Login({
    status,
    canResetPassword,
}: {
    status?: string;
    canResetPassword: boolean;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false as boolean,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    const fillDemo = (email: string) => {
        setData({
            email,
            password: 'password',
            remember: true,
        });
    };

    return (
        <GuestLayout>
            <Head title="Sign In — Bagoo" />

            {status && (
                <div className="mb-4 text-sm font-medium text-emerald-600">
                    {status}
                </div>
            )}

            <div className="mb-6 text-center">
                <h2 className="text-xl font-black text-slate-900">Welcome back to Bagoo</h2>
                <p className="text-xs text-slate-500 mt-1">Sign in with your multi-role account</p>
            </div>

            {/* Quick Demo Role Selectors */}
            <div className="mb-6 p-4 rounded-2xl bg-slate-50 border border-slate-200/80 space-y-2.5">
                <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">Quick Test Sign-In (Demo Credentials)</p>
                <div className="grid grid-cols-2 gap-2">
                    <button
                        type="button"
                        onClick={() => fillDemo('buyer@bagoo.test')}
                        className="p-2 text-left bg-white hover:bg-indigo-50 border border-slate-200 rounded-xl transition group"
                    >
                        <span className="block text-[11px] font-bold text-indigo-700">Buyer</span>
                        <span className="block text-[10px] text-slate-400">buyer@bagoo.test</span>
                    </button>

                    <button
                        type="button"
                        onClick={() => fillDemo('seller@bagoo.test')}
                        className="p-2 text-left bg-white hover:bg-emerald-50 border border-slate-200 rounded-xl transition group"
                    >
                        <span className="block text-[11px] font-bold text-emerald-700">Seller</span>
                        <span className="block text-[10px] text-slate-400">seller@bagoo.test</span>
                    </button>

                    <button
                        type="button"
                        onClick={() => fillDemo('courier@bagoo.test')}
                        className="p-2 text-left bg-white hover:bg-amber-50 border border-slate-200 rounded-xl transition group"
                    >
                        <span className="block text-[11px] font-bold text-amber-700">Courier</span>
                        <span className="block text-[10px] text-slate-400">courier@bagoo.test</span>
                    </button>

                    <button
                        type="button"
                        onClick={() => fillDemo('admin@bagoo.test')}
                        className="p-2 text-left bg-white hover:bg-rose-50 border border-slate-200 rounded-xl transition group"
                    >
                        <span className="block text-[11px] font-bold text-rose-700">Admin</span>
                        <span className="block text-[10px] text-slate-400">admin@bagoo.test</span>
                    </button>
                </div>
            </div>

            <form onSubmit={submit} className="space-y-4">
                <div>
                    <InputLabel htmlFor="email" value="Email Address" />

                    <TextInput
                        id="email"
                        type="email"
                        name="email"
                        value={data.email}
                        className="mt-1 block w-full text-xs"
                        autoComplete="username"
                        isFocused={true}
                        onChange={(e) => setData('email', e.target.value)}
                    />

                    <InputError message={errors.email} className="mt-1" />
                </div>

                <div>
                    <InputLabel htmlFor="password" value="Password" />

                    <TextInput
                        id="password"
                        type="password"
                        name="password"
                        value={data.password}
                        className="mt-1 block w-full text-xs"
                        autoComplete="current-password"
                        onChange={(e) => setData('password', e.target.value)}
                    />

                    <InputError message={errors.password} className="mt-1" />
                </div>

                <div className="flex items-center justify-between text-xs">
                    <label className="flex items-center cursor-pointer">
                        <Checkbox
                            name="remember"
                            checked={data.remember}
                            onChange={(e) =>
                                setData(
                                    'remember',
                                    (e.target.checked || false) as false,
                                )
                            }
                        />
                        <span className="ms-2 text-slate-600">
                            Remember me
                        </span>
                    </label>

                    {canResetPassword && (
                        <Link
                            href={route('password.request')}
                            className="text-xs text-indigo-600 hover:underline font-semibold"
                        >
                            Forgot password?
                        </Link>
                    )}
                </div>

                <div className="pt-2">
                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs rounded-xl shadow-md shadow-indigo-600/20 transition active:scale-95 disabled:opacity-50"
                    >
                        Sign In
                    </button>
                </div>

                <div className="text-center pt-2 text-xs text-slate-500">
                    Don't have an account?{' '}
                    <Link href={route('register')} className="text-indigo-600 font-bold hover:underline">
                        Create an account
                    </Link>
                </div>
            </form>
        </GuestLayout>
    );
}
