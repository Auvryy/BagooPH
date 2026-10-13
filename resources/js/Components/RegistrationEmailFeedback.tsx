import type { EmailAvailability } from '@/utils/registrationEmailAvailability';

export default function RegistrationEmailFeedback({ state, error, onRetry }: {
    state: EmailAvailability;
    error?: string;
    onRetry: () => void;
}) {
    const message = error || (state.status === 'checking' ? 'Checking email…'
        : state.status === 'available' ? 'Email is available. Verify it next.'
        : state.status === 'taken' ? 'This email is already registered. Use another email or sign in.'
        : state.message);
    const invalid = Boolean(error) || state.status === 'taken' || state.status === 'invalid';
    return (
        <div id="registration-email-feedback" role="status" aria-live="polite" aria-atomic="true" className={message ? 'mt-1 text-xs' : ''}>
            {message && (
                <p className={invalid ? 'text-[#E00D42]' : state.status === 'available' ? 'text-emerald-700' : 'text-slate-600'}>
                    {message}
                    {!error && state.status === 'error' && (
                        <button type="button" onClick={onRetry} className="ml-2 rounded-lg font-semibold underline underline-offset-2 focus:outline-none focus:ring-2 focus:ring-[#E00D42]/30">
                            Try again
                        </button>
                    )}
                </p>
            )}
        </div>
    );
}
