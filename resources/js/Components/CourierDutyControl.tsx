import { router } from '@inertiajs/react';
import { Power } from 'lucide-react';
import { useRef, useState } from 'react';
import { CourierDialog, CourierFieldError, courierButton, courierClasses, courierPrimary } from '@/Components/CourierUI';
import { courierPath } from '@/utils/courier';
import { PageProps } from '@/types';
import useCourierRequestError from '@/hooks/useCourierRequestError';

export function CourierDutySwitch({ isOnline, busy = false, onChange }: { isOnline: boolean; busy?: boolean; onChange: () => void }) {
    return <button type="button" role="switch" aria-label="Rider duty" aria-checked={isOnline} aria-busy={busy} disabled={busy} onClick={onChange} title={isOnline ? 'Go off duty' : 'Go on duty'} className={courierClasses(courierButton, 'gap-2 px-3 font-medium')}>
        <span className={`h-2 w-2 shrink-0 rounded-full ${isOnline ? 'bg-emerald-600' : 'bg-slate-500'}`} aria-hidden="true" />
        <span>{busy ? 'Updating…' : isOnline ? 'On duty' : 'Off duty'}</span>
        <svg width="40" height="24" viewBox="0 0 40 24" aria-hidden="true" focusable="false" className="ml-1 h-6 w-10 shrink-0">
            <rect width="40" height="24" rx="12" fill={isOnline ? '#E00D42' : '#64748B'} />
            <circle cx={isOnline ? 28 : 12} cy="12" r="9" fill="#FFFFFF" />
        </svg>
    </button>;
}

export function useCourierDutyControl(isOnline: boolean) {
    const [dutyLoading, setDutyLoading] = useState(false);
    const [dutyError, setDutyError] = useState('');
    const [confirmingOffDuty, setConfirmingOffDuty] = useState(false);
    const pending = useRef(false);
    useCourierRequestError(pending, setDutyError);

    const updateDuty = (isAvailable: boolean) => {
        if (pending.current) return;
        pending.current = true;
        setDutyLoading(true);
        setDutyError('');
        router.post(courierPath('/profile/toggle-duty'), { is_available: isAvailable }, {
            preserveScroll: true,
            onSuccess: (page) => {
                const error = (page.props as PageProps).flash?.error;
                if (error) setDutyError(error);
                else setConfirmingOffDuty(false);
            },
            onError: (errors) => setDutyError(errors.is_available || 'Could not change duty status. Try again.'),
            onFinish: () => { pending.current = false; setDutyLoading(false); },
        });
    };

    const requestDutyChange = () => {
        if (pending.current) return;
        setDutyError('');
        if (isOnline) setConfirmingOffDuty(true);
        else updateDuty(true);
    };

    const confirmationDialog = (
        <CourierDialog open={confirmingOffDuty} title="Go off duty?" onClose={() => setConfirmingOffDuty(false)} busy={dutyLoading}>
            <p className="text-base leading-relaxed text-slate-700">You will stop receiving new pickup and final-mile assignments.</p>
            <p className="mt-4 rounded-[16px] border border-amber-300 bg-[#FFF4DF] p-4 text-base leading-relaxed text-[#92400E]">Parcels already assigned to you remain your responsibility. You can still record pickup and delivery, and bring collected parcels to the origin hub.</p>
            <CourierFieldError message={dutyError} />
            <div className="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" disabled={dutyLoading} onClick={() => setConfirmingOffDuty(false)} className={courierButton}>Keep working</button>
                <button type="button" disabled={dutyLoading} onClick={() => updateDuty(false)} className={courierPrimary}><Power className="h-4 w-4" aria-hidden="true" />{dutyLoading ? 'Updating…' : 'Go off duty'}</button>
            </div>
        </CourierDialog>
    );
    return { confirmationDialog, dutyLoading, dutyError, requestDutyChange };
}
