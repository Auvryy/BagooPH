import { QRCodeSVG } from 'qrcode.react';
import Barcode from './Barcode';

export default function WaybillCodes({ trackingNumber, compact = false }: { trackingNumber: string; compact?: boolean }) {
    if (!trackingNumber) return <p className="py-3 text-sm text-slate-600">The waybill code is not available yet.</p>;
    return <div className="space-y-6 border-y border-slate-300 bg-white py-3 print:border-black" data-waybill-codes>
        <div className="space-y-2" data-waybill-barcode>
            <p className="text-center text-[10px] font-semibold uppercase text-slate-600">Tracking barcode</p>
            <div className="flex justify-center bg-white px-3 py-2">
                <Barcode value={trackingNumber} height={compact ? 28 : 40} width={compact ? 1.2 : 1.6} margin={12} background="#ffffff" className="h-auto max-w-full" />
            </div>
            <p className="break-all text-center text-xs font-bold text-slate-900">{trackingNumber}</p>
        </div>
        <div className="flex items-center justify-between gap-6 border-t border-slate-300 pt-4 print:border-black" data-waybill-qr>
            <div className="space-y-1 text-[10px] text-slate-600">
                <p className="font-semibold text-slate-900">Alternative parcel QR</p>
                <p>Both codes identify the same parcel.</p>
                <p>Point the camera at one code.</p>
            </div>
            <QRCodeSVG value={trackingNumber} size={compact ? 64 : 80} level="M" marginSize={4} className="shrink-0 bg-white" title={`Parcel ${trackingNumber}`} />
        </div>
    </div>;
}
