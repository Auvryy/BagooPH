import { useEffect, useRef, useState } from 'react';
import { AuthRequestError, authRequest, registrationErrors, registrationErrorStep, registrationFormData } from '@/utils/authRequests';

export function useRegistrationValidation(data: Record<string, unknown>, onErrors: (errors: Record<string, string>, step: number) => void) {
    const [validating, setValidating] = useState(false);
    const busy = useRef(false);
    const feedbackRef = useRef<HTMLDivElement>(null);
    const [feedbackRevision, setFeedbackRevision] = useState(0);
    const latestData = useRef(data);
    latestData.current = data;

    useEffect(() => { if (feedbackRevision) feedbackRef.current?.focus(); }, [feedbackRevision]);

    const applyErrors = (errors: Record<string, string>) => {
        const role = String(data.role);
        onErrors(registrationErrors(role, errors), registrationErrorStep(role, errors));
        setFeedbackRevision(value => value + 1);
    };

    const validate = async (step?: number): Promise<boolean> => {
        if (busy.current) return false;
        busy.current = true;
        setValidating(true);
        const snapshot = data;
        try {
            const reply = await authRequest('/register/validate', registrationFormData(snapshot, step));
            if (latestData.current !== snapshot) return false;
            if (reply.valid !== true) throw new AuthRequestError('We could not confirm your details. Please try again.');
            onErrors({}, step ?? registrationErrorStep(String(data.role), {}));
            return true;
        } catch (error) {
            if (latestData.current !== snapshot) return false;
            if (error instanceof AuthRequestError && Object.keys(error.fields).length) applyErrors(error.fields);
            else {
                onErrors({ request: error instanceof Error ? error.message : 'We could not check your details. Please try again.' }, step ?? registrationErrorStep(String(data.role), {}));
                setFeedbackRevision(value => value + 1);
            }
            return false;
        } finally {
            busy.current = false;
            setValidating(false);
        }
    };

    return { validate, validating, applyErrors, feedbackRef };
}
