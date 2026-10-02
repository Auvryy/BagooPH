import { router } from '@inertiajs/react';
import { AlertTriangle, Power, X } from 'lucide-react';
import { useState } from 'react';

export function useCourierDutyControl(isOnline: boolean) {
    const [dutyLoading, setDutyLoading] = useState(false);
    const [confirmingOffDuty, setConfirmingOffDuty] = useState(false);

    const updateDuty = (isAvailable: boolean) => {
        setDutyLoading(true);
        router.post(
            route('courier.toggleDuty'),
            { is_available: isAvailable },
            {
                preserveScroll: true,
                onFinish: () => setDutyLoading(false),
            },
        );
    };

    const requestDutyChange = () => {
        if (isOnline) {
            setConfirmingOffDuty(true);
            return;
        }

        updateDuty(true);
    };

    const confirmationDialog = confirmingOffDuty ? (
        <div
            className="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/50 p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="off-duty-dialog-title"
        >
            <div className="w-full max-w-md rounded-lg border border-slate-300 bg-white shadow-2xl">
                <div className="flex items-start justify-between border-b border-slate-200 p-5">
                    <div className="flex items-start gap-3">
                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-rose-200 bg-rose-50 text-[#E00D42]">
                            <AlertTriangle className="h-4 w-4" />
                        </div>
                        <div>
                            <h2 id="off-duty-dialog-title" className="text-base font-bold text-slate-950">
                                Go off duty?
                            </h2>
                            <p className="mt-1 text-xs leading-5 text-slate-600">
                                You will no longer appear in new pickup or final-mile assignment lists.
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={() => setConfirmingOffDuty(false)}
                        disabled={dutyLoading}
                        className="rounded-sm p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 disabled:opacity-50"
                        aria-label="Cancel off-duty change"
                    >
                        <X className="h-4 w-4" />
                    </button>
                </div>

                <div className="space-y-3 p-5">
                    <p className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs leading-5 text-amber-900">
                        Any parcel already assigned to you remains your responsibility. You can still complete its pickup,
                        hub handoff, or final-mile delivery.
                    </p>
                </div>

                <div className="flex flex-col-reverse gap-2 border-t border-slate-200 p-4 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        onClick={() => setConfirmingOffDuty(false)}
                        disabled={dutyLoading}
                        className="rounded-sm border border-slate-300 px-3.5 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50 disabled:opacity-50"
                    >
                        Keep working
                    </button>
                    <button
                        type="button"
                        onClick={() => {
                            setConfirmingOffDuty(false);
                            updateDuty(false);
                        }}
                        disabled={dutyLoading}
                        className="inline-flex items-center justify-center gap-1.5 rounded-sm bg-[#E00D42] px-3.5 py-2 text-xs font-bold text-white transition hover:bg-[#C20836] disabled:opacity-50"
                    >
                        <Power className="h-3.5 w-3.5" />
                        {dutyLoading ? 'Updating...' : 'Go off duty'}
                    </button>
                </div>
            </div>
        </div>
    ) : null;

    return {
        confirmationDialog,
        dutyLoading,
        requestDutyChange,
    };
}
