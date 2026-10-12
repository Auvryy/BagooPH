import InputError from '@/Components/InputError';

export type RegistrationPolicies = {
    required: boolean;
    terms_version: string;
    privacy_version: string;
    terms_url: string;
    privacy_url: string;
};

export default function RegistrationPolicyAcknowledgement({ policies, accepted, onChange, error, courier = false }: {
    policies: RegistrationPolicies;
    accepted: boolean;
    onChange: (accepted: boolean) => void;
    error?: string;
    courier?: boolean;
}) {
    return <div className="space-y-2 rounded-lg border border-slate-300 bg-slate-50 p-3 text-sm">
        <div className="flex items-start gap-2.5">
            <input id="policy-accepted" name="policy_accepted" type="checkbox" checked={accepted} required
                aria-invalid={Boolean(error)} aria-describedby={error ? 'policy-error' : undefined}
                onChange={event => onChange(event.target.checked)}
                className={`mt-0.5 rounded border-slate-300 ${courier ? 'text-emerald-600 focus:ring-emerald-600' : 'text-[#E00D42] focus:ring-[#E00D42]'}`} />
            <label htmlFor="policy-accepted" className="leading-relaxed text-slate-700">
                I agree to the <a href={policies.terms_url} target="_blank" rel="noopener noreferrer" className="font-semibold underline underline-offset-2">Terms of Use</a> and acknowledge the <a href={policies.privacy_url} target="_blank" rel="noopener noreferrer" className="font-semibold underline underline-offset-2">Privacy Notice</a>.
            </label>
        </div>
        <p className="text-xs text-slate-500">This does not subscribe you to promotional messages. The links open in a new tab so your application stays here.</p>
        <InputError id="policy-error" message={error} role="alert" />
    </div>;
}
