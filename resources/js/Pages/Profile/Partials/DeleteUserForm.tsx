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
            <header className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-lg font-semibold text-ink-900">Close account</h2>
                    <details className="mt-1 text-[13px] text-ink-600">
                        <summary className="cursor-pointer font-medium text-ink-700 underline decoration-line-300 underline-offset-4">What happens to my records</summary>
                        <p className="mt-1.5 max-w-2xl">{closure.retention_note}</p>
                    </details>
                </div>
                {closure.blockers.length > 0
                    ? <span className="rounded-chip bg-warning-soft px-2 py-1 text-xs font-medium text-warning">Blocked by {closure.blockers.length} open {closure.blockers.length === 1 ? 'item' : 'items'}</span>
                    : closure.allowed && <span className="rounded-chip bg-success-soft px-2 py-1 text-xs font-medium text-success">Ready to close</span>}
            </header>

            {closure.blockers.length > 0 && (() => {
                const groups = closure.blockers.reduce<{ next: string; items: string[] }[]>((all, blocker) => {
                    const group = all.find(item => item.next === blocker.next);
                    if (group) group.items.push(blocker.message); else all.push({ next: blocker.next, items: [blocker.message] });
                    return all;
                }, []);
                return (
                    <details className="rounded-control border border-line-200">
                        <summary className="cursor-pointer px-4 py-3 text-sm font-medium text-ink-900">See what needs to be resolved</summary>
                        <ul className="divide-y divide-line-200 border-t border-line-200">
                            {groups.map(group => {
                                const refs = group.items.map(item => item.match(/[A-Z]{2,4}-[A-Z0-9]{4,}/)?.[0]).filter((ref): ref is string => Boolean(ref));
                                return (
                                    <li key={group.next} className="px-4 py-3 text-sm">
                                        <p className="font-medium text-ink-900">{group.next}</p>
                                        {refs.length === group.items.length ? (
                                            <p className="mt-1.5 flex flex-wrap gap-1.5">{refs.map(ref => <span key={ref} className="rounded-chip bg-surface-100 px-1.5 py-0.5 text-xs tabular-nums text-ink-700">{ref}</span>)}</p>
                                        ) : (
                                            <ul className="mt-1 space-y-0.5 text-[13px] text-ink-600">{group.items.map((item, index) => <li key={index}>{item}</li>)}</ul>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    </details>
                );
            })()}
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
