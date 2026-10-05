import DangerButton from '@/Components/DangerButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { router, useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useRef, useState } from 'react';
import { AccountClosureReview } from '@/types/accountClosure';

export default function DeleteUserForm({
    className = '',
    closure,
}: {
    className?: string;
    closure: AccountClosureReview;
}) {
    const [confirmingUserDeletion, setConfirmingUserDeletion] = useState(false);
    const passwordInput = useRef<HTMLInputElement>(null);

    const {
        data,
        setData,
        delete: destroy,
        processing,
        reset,
        errors,
        clearErrors,
    } = useForm({
        password: '',
        source_token: closure.source_token,
    });

    useEffect(() => {
        setData('source_token', closure.source_token);
        if (!closure.allowed) setConfirmingUserDeletion(false);
    }, [closure.source_token, closure.allowed]);

    const confirmUserDeletion = () => {
        setConfirmingUserDeletion(true);
    };

    const deleteUser: FormEventHandler = (e) => {
        e.preventDefault();

        destroy(route('profile.destroy'), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
            onError: () => passwordInput.current?.focus(),
            onFinish: () => reset('password'),
        });
    };

    const closeModal = () => {
        setConfirmingUserDeletion(false);

        clearErrors();
        reset('password');
    };

    return (
        <section className={`space-y-6 ${className}`}>
            <header>
                <h2 className="text-lg font-medium text-gray-900">
                    Close account
                </h2>

                <p className="mt-1 text-sm text-gray-600">
                    {closure.retention_note}
                </p>
            </header>

            {closure.blockers.map((blocker, index) => <div key={index} className="text-sm"><p className="font-medium text-slate-900">{blocker.message}</p><p className="mt-1 text-slate-600">{blocker.next}</p></div>)}
            {closure.allowed && <DangerButton onClick={confirmUserDeletion}>
                Close unused account
            </DangerButton>}

            <Modal show={confirmingUserDeletion} onClose={closeModal}>
                <form onSubmit={deleteUser} className="p-6">
                    <h2 className="text-lg font-medium text-gray-900">
                        Close this unused account?
                    </h2>

                    <p className="mt-1 text-sm text-gray-600">
                        This account has no related records or stored files. Enter your password to confirm removal. Your current responsibilities will be checked again before the account is closed.
                    </p>

                    <div className="mt-6">
                        <InputLabel
                            htmlFor="password"
                            value="Password"
                            className="sr-only"
                        />

                        <TextInput
                            id="password"
                            type="password"
                            name="password"
                            ref={passwordInput}
                            value={data.password}
                            onChange={(e) =>
                                setData('password', e.target.value)
                            }
                            className="mt-1 block w-3/4"
                            isFocused
                            placeholder="Password"
                        />

                        <InputError
                            message={errors.password}
                            className="mt-2"
                        />
                        <InputError message={errors.source_token} className="mt-2" />
                        {errors.source_token && <button type="button" onClick={() => router.reload({ only: ['closure'], onSuccess: () => clearErrors('source_token') })} className="mt-3 rounded-lg border border-slate-300 px-3 py-2 text-sm">Reload closure checks</button>}
                    </div>

                    <div className="mt-6 flex justify-end">
                        <SecondaryButton onClick={closeModal}>
                            Cancel
                        </SecondaryButton>

                        <DangerButton className="ms-3" disabled={processing || !closure.allowed || !!errors.source_token}>
                            Close account
                        </DangerButton>
                    </div>
                </form>
            </Modal>
        </section>
    );
}
