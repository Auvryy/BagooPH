import { Dispatch, SetStateAction, useCallback, useEffect, useRef, useState } from 'react';
import {
    clearRegistrationDraft,
    loadRegistrationDraft,
    RegistrationDraftRole,
    saveRegistrationDraft,
} from '@/utils/registrationDraft';

type RegistrationDraftOptions<T extends Record<string, unknown>> = {
    role: RegistrationDraftRole;
    data: T;
    setData: (data: Partial<T>) => void;
    step: number;
    setStep: Dispatch<SetStateAction<number>>;
    verificationOpen: boolean;
    setVerificationOpen: Dispatch<SetStateAction<boolean>>;
};

export function useRegistrationDraft<T extends Record<string, unknown>>({
    role,
    data,
    setData,
    step,
    setStep,
    verificationOpen,
    setVerificationOpen,
}: RegistrationDraftOptions<T>) {
    const [restored, setRestored] = useState(false);
    const restoreAttempted = useRef(false);
    const cleared = useRef(false);

    useEffect(() => {
        if (restoreAttempted.current) return;
        restoreAttempted.current = true;
        const draft = loadRegistrationDraft(role);
        if (draft) {
            setData({ ...data, ...draft.fields } as Partial<T>);
            setStep(draft.step);
            setVerificationOpen(draft.verificationOpen);
        }
        setRestored(true);
    }, [data, role, setData, setStep, setVerificationOpen]);

    useEffect(() => {
        if (!restored || cleared.current) return;
        saveRegistrationDraft(role, data, step, verificationOpen);
    }, [data, restored, role, step, verificationOpen]);

    const clearDraft = useCallback(() => {
        cleared.current = true;
        clearRegistrationDraft(role);
    }, [role]);

    return { clearDraft };
}
