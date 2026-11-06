import EmailVerificationStatus from '@/Components/EmailVerificationStatus';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { PageProps } from '@/types';
import { Transition } from '@headlessui/react';
import { useForm, usePage } from '@inertiajs/react';
import axios from 'axios';
import { FormEventHandler, useRef, useState } from 'react';

function responseError(reason: unknown, fallback: string): string {
    if (!axios.isAxiosError(reason)) return fallback;

    const payload = reason.response?.data as { errors?: Record<string, string[]>; message?: string } | undefined;
    const validationMessage = payload?.errors ? Object.values(payload.errors)[0]?.[0] : undefined;

    return validationMessage ?? payload?.message ?? fallback;
}

export default function UpdatePasswordForm({
    className = '',
    action = '/password',
}: {
    className?: string;
    action?: string;
}) {
    const user = usePage<PageProps>().props.auth.user;
    const googleLinked = Boolean(user?.has_google_identity);
    const [needsGooglePasswordSetup, setNeedsGooglePasswordSetup] = useState(Boolean(user?.needs_password_setup));
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);
    const [code, setCode] = useState('');
    const [googlePassword, setGooglePassword] = useState('');
    const [googlePasswordConfirmation, setGooglePasswordConfirmation] = useState('');
    const [googleError, setGoogleError] = useState('');
    const [googleMessage, setGoogleMessage] = useState('');
    const [sendingCode, setSendingCode] = useState(false);
    const [settingGooglePassword, setSettingGooglePassword] = useState(false);

    const {
        data,
        setData,
        errors,
        put,
        reset,
        processing,
        recentlySuccessful,
    } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const updatePassword: FormEventHandler = (e) => {
        e.preventDefault();

        put(action, {
            preserveScroll: true,
            onSuccess: () => reset(),
            onError: (errors) => {
                if (errors.password) {
                    reset('password', 'password_confirmation');
                    passwordInput.current?.focus();
                }

                if (errors.current_password) {
                    reset('current_password');
                    currentPasswordInput.current?.focus();
                }
            },
        });
    };

    const sendGoogleCode = async () => {
        if (sendingCode) return;
        setSendingCode(true);
        setGoogleError('');
        setGoogleMessage('');
        try {
            const response = await axios.post<{ message: string }>('/password/google/code', {}, { headers: { Accept: 'application/json' } });
            setGoogleMessage(response.data.message);
        } catch (reason) {
            setGoogleError(responseError(reason, 'We could not send a verification code. Please try again.'));
        } finally {
            setSendingCode(false);
        }
    };

    const setGoogleAccountPassword: FormEventHandler = async (event) => {
        event.preventDefault();
        if (settingGooglePassword) return;
        setSettingGooglePassword(true);
        setGoogleError('');
        setGoogleMessage('');
        try {
            const response = await axios.put<{ message: string }>('/password/google', {
                code, password: googlePassword, password_confirmation: googlePasswordConfirmation,
            }, { headers: { Accept: 'application/json' } });
            setGoogleMessage(response.data.message);
            setNeedsGooglePasswordSetup(false);
            setCode('');
            setGooglePassword('');
            setGooglePasswordConfirmation('');
        } catch (reason) {
            setGoogleError(responseError(reason, 'We could not set your password. Please try again.'));
        } finally {
            setSettingGooglePassword(false);
        }
    };

    return (
        <section className={className}>
            <header>
                <h2 className="text-lg font-medium text-gray-900">
                    Update Password
                </h2>

                <p className="mt-1 text-sm text-gray-600">
                    Use 8–128 characters with uppercase and lowercase letters, a number and a symbol.
                </p>
            </header>

            {user && (
                <EmailVerificationStatus
                    email={user.email}
                    verifiedAt={user.email_verified_at}
                    className="mt-5"
                />
            )}
            {googleMessage && <p role="status" className="mt-5 text-sm text-emerald-700">{googleMessage}</p>}

            {user?.email_verified_at && (
                googleLinked && needsGooglePasswordSetup ? (
                    <form onSubmit={setGoogleAccountPassword} className="mt-6 space-y-6">
                        <p className="text-sm leading-relaxed text-gray-600">Your account uses Google sign-in. To add email and password sign-in, first verify your Bagoo email with a fresh code.</p>
                        <div>
                            <InputLabel htmlFor="google-password-code" value="Email verification code" />
                            <div className="mt-1 flex flex-col gap-2 sm:flex-row">
                                <TextInput id="google-password-code" name="code" value={code} onChange={event => setCode(event.target.value.replace(/\D/g, '').slice(0, 6))}
                                    type="text" inputMode="numeric" autoComplete="one-time-code" maxLength={6} pattern="[0-9]{6}" className="block w-full sm:max-w-48" />
                                <button type="button" onClick={sendGoogleCode} disabled={sendingCode} className="min-h-11 rounded-md border border-gray-300 px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50">
                                    {sendingCode ? 'Sending…' : 'Send a fresh code'}
                                </button>
                            </div>
                        </div>
                        <div>
                            <InputLabel htmlFor="google-new-password" value="New Password" />
                            <TextInput id="google-new-password" name="password" value={googlePassword} onChange={event => setGooglePassword(event.target.value)} type="password" autoComplete="new-password" minLength={8} maxLength={128} className="mt-1 block w-full" />
                        </div>
                        <div>
                            <InputLabel htmlFor="google-password-confirmation" value="Confirm Password" />
                            <TextInput id="google-password-confirmation" name="password_confirmation" value={googlePasswordConfirmation} onChange={event => setGooglePasswordConfirmation(event.target.value)} type="password" autoComplete="new-password" minLength={8} maxLength={128} className="mt-1 block w-full" />
                        </div>
                        {googleError && <p role="alert" className="text-sm text-rose-700">{googleError}</p>}
                        <PrimaryButton disabled={settingGooglePassword || code.length !== 6}>{settingGooglePassword ? 'Setting password…' : 'Set email password'}</PrimaryButton>
                    </form>
                ) : (
                <form onSubmit={updatePassword} className="mt-6 space-y-6">
                    {googleLinked && <p className="text-sm leading-relaxed text-gray-600">Email and password sign-in is enabled. Google sign-in remains available.</p>}
                    <div>
                        <InputLabel
                            htmlFor="current_password"
                            value="Current Password"
                        />

                        <TextInput
                            id="current_password"
                            ref={currentPasswordInput}
                            value={data.current_password}
                            onChange={(e) =>
                                setData('current_password', e.target.value)
                            }
                            type="password"
                            className="mt-1 block w-full"
                            autoComplete="current-password"
                        />

                        <InputError
                            message={errors.current_password}
                            className="mt-2"
                        />
                    </div>

                    <div>
                        <InputLabel htmlFor="password" value="New Password" />

                        <TextInput
                            id="password"
                            ref={passwordInput}
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            type="password"
                            className="mt-1 block w-full"
                            autoComplete="new-password"
                            minLength={8} maxLength={128}
                        />

                        <InputError message={errors.password} className="mt-2" />
                    </div>

                    <div>
                        <InputLabel
                            htmlFor="password_confirmation"
                            value="Confirm Password"
                        />

                        <TextInput
                            id="password_confirmation"
                            value={data.password_confirmation}
                            onChange={(e) =>
                                setData('password_confirmation', e.target.value)
                            }
                            type="password"
                            className="mt-1 block w-full"
                            autoComplete="new-password"
                            minLength={8} maxLength={128}
                        />

                        <InputError
                            message={errors.password_confirmation}
                            className="mt-2"
                        />
                    </div>

                    <div className="flex items-center gap-4">
                        <PrimaryButton disabled={processing}>Save</PrimaryButton>

                        <Transition
                            show={recentlySuccessful}
                            enter="transition ease-in-out"
                            enterFrom="opacity-0"
                            leave="transition ease-in-out"
                            leaveTo="opacity-0"
                        >
                            <p className="text-sm text-gray-600">Saved.</p>
                        </Transition>
                    </div>
                </form>
                )
            )}
        </section>
    );
}
