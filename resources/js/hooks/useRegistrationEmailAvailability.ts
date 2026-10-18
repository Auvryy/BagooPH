import { useEffect, useRef, useState } from 'react';
import { normalizeRegistrationEmail } from '@/utils/authRequests';
import { RegistrationEmailAvailability, type EmailAvailability } from '@/utils/registrationEmailAvailability';

export function useRegistrationEmailAvailability(email: string, enabled = true, role = 'buyer') {
    const normalized = normalizeRegistrationEmail(email);
    const [feedback, setFeedback] = useState<EmailAvailability>({ email: '', status: 'idle' });
    const controller = useRef<RegistrationEmailAvailability>();
    if (!controller.current) controller.current = new RegistrationEmailAvailability(setFeedback);

    useEffect(() => {
        controller.current!.update(normalized, enabled, role);
        return () => controller.current!.cancel();
    }, [normalized, enabled, role]);

    const checkNow = () => {
        controller.current!.update(normalized, enabled, role);
        void controller.current!.checkNow();
    };
    const state: EmailAvailability = enabled && feedback.email === normalized && feedback.role === role ? feedback : { email: normalized, role, status: 'idle' };
    return { state, checkNow, invalid: state.status === 'taken' || state.status === 'invalid' };
}
