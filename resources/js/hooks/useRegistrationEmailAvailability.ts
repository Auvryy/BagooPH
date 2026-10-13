import { useEffect, useRef, useState } from 'react';
import { normalizeRegistrationEmail } from '@/utils/authRequests';
import { RegistrationEmailAvailability, type EmailAvailability } from '@/utils/registrationEmailAvailability';

export function useRegistrationEmailAvailability(email: string, enabled = true) {
    const normalized = normalizeRegistrationEmail(email);
    const [feedback, setFeedback] = useState<EmailAvailability>({ email: '', status: 'idle' });
    const controller = useRef<RegistrationEmailAvailability>();
    if (!controller.current) controller.current = new RegistrationEmailAvailability(setFeedback);

    useEffect(() => {
        controller.current!.update(normalized, enabled);
        return () => controller.current!.cancel();
    }, [normalized, enabled]);

    const checkNow = () => {
        controller.current!.update(normalized, enabled);
        void controller.current!.checkNow();
    };
    const state: EmailAvailability = enabled && feedback.email === normalized ? feedback : { email: normalized, status: 'idle' };
    return { state, checkNow, invalid: state.status === 'taken' || state.status === 'invalid' };
}
