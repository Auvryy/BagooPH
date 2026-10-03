import { router } from '@inertiajs/react';
import { Power } from 'lucide-react';
import { useRef, useState } from 'react';
import { CourierDialog, CourierFieldError, courierButton, courierPrimary } from '@/Components/CourierUI';
import { courierPath } from '@/utils/courier';
import { PageProps } from '@/types';
import useCourierRequestError from '@/hooks/useCourierRequestError';

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
            <p className="mt-4 rounded-[18px] border border-amber-300 bg-[#FFF4DF] p-4 text-base leading-relaxed text-[#92400E]">Parcels already assigned to you remain your responsibility. You can still record pickup and delivery, and bring collected parcels to the origin hub.</p>
            <CourierFieldError message={dutyError} />
            <div className="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" disabled={dutyLoading} onClick={() => setConfirmingOffDuty(false)} className={courierButton}>Keep working</button>
                <button type="button" disabled={dutyLoading} onClick={() => updateDuty(false)} className={courierPrimary}><Power className="h-4 w-4" aria-hidden="true" />{dutyLoading ? 'Updating…' : 'Go off duty'}</button>
            </div>
        </CourierDialog>
    );
    return { confirmationDialog, dutyLoading, dutyError, requestDutyChange };
}
