import React, { useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { 
    ShieldCheck, 
    ShieldAlert, 
    Upload, 
    X, 
    FileText, 
    Check, 
    AlertCircle,
    ArrowRight,
    Sparkles
} from 'lucide-react';
import { User } from '@/types';

interface Props {
    isOpen: boolean;
    onClose: () => void;
    onDismiss: () => void;
    user: User;
}

export default function BuyerIdVerificationModal({
    isOpen,
    onClose,
    onDismiss,
    user
}: Props) {
    const [file, setFile] = useState<File | null>(null);
    const [filePreview, setFilePreview] = useState<string | null>(null);
    const [uploading, setUploading] = useState(false);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const fileInputRef = useRef<HTMLInputElement>(null);

    if (!isOpen) return null;

    const handleFileSelect = (selectedFile: File | undefined) => {
        if (!selectedFile) return;

        // Validation: Max 5MB
        if (selectedFile.size > 5 * 1024 * 1024) {
            setErrorMessage('File size exceeds 5MB limit. Please choose a smaller file.');
            return;
        }

        // Validation: Allowed types
        const allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp', 'application/pdf'];
        if (!allowedTypes.includes(selectedFile.type)) {
            setErrorMessage('Invalid file format. Please upload JPG, PNG, WEBP, or PDF.');
            return;
        }

        setErrorMessage(null);
        setFile(selectedFile);

        if (selectedFile.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = (e) => setFilePreview(e.target?.result as string);
            reader.readAsDataURL(selectedFile);
        } else {
            setFilePreview(null);
        }
    };

    const handleDrop = (e: React.DragEvent<HTMLDivElement>) => {
        e.preventDefault();
        e.stopPropagation();
        if (e.dataTransfer.files && e.dataTransfer.files[0]) {
            handleFileSelect(e.dataTransfer.files[0]);
        }
    };

    const handleDragOver = (e: React.DragEvent<HTMLDivElement>) => {
        e.preventDefault();
        e.stopPropagation();
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!file || uploading) return;

        setUploading(true);
        setErrorMessage(null);

        const formData = new FormData();
        formData.append('id_document', file);

        router.post(route('buyer.kyc.upload'), formData, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setUploading(false);
                setFile(null);
                setFilePreview(null);
                onClose();
            },
            onError: (errors) => {
                setUploading(false);
                setErrorMessage(errors.id_document || 'Failed to upload ID document. Please try again.');
            },
        });
    };

    const isRejected = user.kyc_status === 'rejected';

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 overflow-y-auto bg-slate-950/60 backdrop-blur-xs animate-fade-in font-sans">
            <div className="relative w-full max-w-lg bg-white rounded-3xl p-6 sm:p-7 shadow-2xl border border-slate-200 animate-scale-in max-h-[92vh] overflow-y-auto">
                
                {/* Close / Dismiss Button */}
                <button
                    type="button"
                    onClick={onDismiss}
                    className="absolute right-4 top-4 p-2 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-xl transition cursor-pointer"
                    title="Maybe later"
                >
                    <X className="w-5 h-5" />
                </button>

                {/* Header Badge */}
                <div className="mb-4">
                    <span className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[10px] font-sans font-bold uppercase tracking-wider border ${
                        isRejected 
                            ? 'bg-rose-50 text-rose-700 border-rose-200' 
                            : 'bg-amber-50 text-amber-700 border-amber-200'
                    }`}>
                        <ShieldAlert className={`w-3.5 h-3.5 ${isRejected ? 'text-rose-600' : 'text-amber-600'}`} />
                        <span>{isRejected ? 'ID Verification Rejected — Action Required' : 'Identity Verification Reminder'}</span>
                    </span>
                </div>

                {/* Title and Intro */}
                <div className="space-y-1.5 mb-5">
                    <h2 className="text-xl font-black tracking-tight text-slate-900 leading-tight">
                        {isRejected ? 'Please Re-Upload a Valid ID' : 'Complete Your Identity Verification'}
                    </h2>
                    <p className="text-xs text-slate-500 leading-relaxed">
                        {isRejected
                            ? (user.kyc_feedback || 'Your previous document could not be verified. Please provide a clear, government-issued photo ID.')
                            : 'To protect our marketplace against bogus orders, upload a government ID to unlock 100% Cash on Delivery guarantee and priority doorstep parcel inspection.'}
                    </p>
                </div>

                {/* Benefits Checklist */}
                <div className="bg-slate-50 rounded-2xl p-3.5 border border-slate-200 mb-5 space-y-2">
                    <div className="flex items-start gap-2.5 text-xs text-slate-700">
                        <div className="w-4 h-4 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0 mt-0.5">
                            <Check className="w-2.5 h-2.5 stroke-[3]" />
                        </div>
                        <span><strong>100% Cash on Delivery Guarantee</strong> with doorstep inspection</span>
                    </div>
                    <div className="flex items-start gap-2.5 text-xs text-slate-700">
                        <div className="w-4 h-4 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0 mt-0.5">
                            <Check className="w-2.5 h-2.5 stroke-[3]" />
                        </div>
                        <span><strong>Verified Buyer Trust Badge</strong> for instant order fulfillment</span>
                    </div>
                    <div className="flex items-start gap-2.5 text-xs text-slate-700">
                        <div className="w-4 h-4 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0 mt-0.5">
                            <Check className="w-2.5 h-2.5 stroke-[3]" />
                        </div>
                        <span><strong>Priority Dispute Resolution</strong> and expedited refund desk</span>
                    </div>
                </div>

                {/* Upload Form */}
                <form onSubmit={handleSubmit} className="space-y-4">
                    <input
                        ref={fileInputRef}
                        type="file"
                        accept="image/jpeg,image/png,image/jpg,image/webp,application/pdf"
                        className="hidden"
                        onChange={(e) => handleFileSelect(e.target.files?.[0])}
                    />

                    {/* Drag & Drop Area */}
                    {!file ? (
                        <div
                            onClick={() => fileInputRef.current?.click()}
                            onDrop={handleDrop}
                            onDragOver={handleDragOver}
                            className="border-2 border-dashed border-slate-300 hover:border-[#E00D42] bg-slate-50/50 hover:bg-rose-50/20 rounded-2xl p-6 text-center transition cursor-pointer group"
                        >
                            <div className="w-11 h-11 rounded-2xl bg-white border border-slate-200 text-slate-600 group-hover:text-[#E00D42] group-hover:border-rose-200 flex items-center justify-center mx-auto mb-2.5 shadow-2xs transition">
                                <Upload className="w-5 h-5" />
                            </div>
                            <p className="text-xs font-bold text-slate-800 group-hover:text-[#E00D42] transition">
                                Click or drag & drop your Valid Government ID
                            </p>
                            <p className="text-[11px] text-slate-400 font-sans mt-1">
                                PhilID / ePhilID, Passport, Driver's License, UMID, SSS, Postal ID (Max 5MB)
                            </p>
                        </div>
                    ) : (
                        <div className="bg-white rounded-2xl p-3.5 border border-slate-200 flex items-center justify-between gap-3 shadow-2xs">
                            <div className="flex items-center gap-3 min-w-0">
                                {filePreview ? (
                                    <img
                                        src={filePreview}
                                        alt="Preview"
                                        className="w-12 h-12 rounded-xl object-cover border border-slate-200 shrink-0"
                                    />
                                ) : (
                                    <div className="w-12 h-12 rounded-xl bg-slate-100 text-slate-600 flex items-center justify-center shrink-0">
                                        <FileText className="w-6 h-6" />
                                    </div>
                                )}
                                <div className="min-w-0">
                                    <p className="text-xs font-bold text-slate-900 truncate">{file.name}</p>
                                    <p className="text-[10px] text-slate-400 font-sans">
                                        {(file.size / (1024 * 1024)).toFixed(2)} MB
                                    </p>
                                </div>
                            </div>

                            <button
                                type="button"
                                onClick={() => {
                                    setFile(null);
                                    setFilePreview(null);
                                }}
                                className="p-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition"
                                title="Remove file"
                            >
                                <X className="w-4 h-4" />
                            </button>
                        </div>
                    )}

                    {errorMessage && (
                        <div className="p-3 bg-rose-50 border border-rose-200 rounded-xl text-xs text-rose-600 flex items-center gap-2">
                            <AlertCircle className="w-4 h-4 shrink-0" />
                            <span>{errorMessage}</span>
                        </div>
                    )}

                    {/* Action Buttons */}
                    <div className="flex items-center gap-3 pt-2">
                        <button
                            type="button"
                            onClick={onDismiss}
                            className="flex-1 py-3 px-4 rounded-xl border border-slate-200 hover:bg-slate-100 text-slate-700 font-bold text-xs transition cursor-pointer text-center font-sans uppercase tracking-wider"
                        >
                            Maybe Later
                        </button>

                        <button
                            type="submit"
                            disabled={!file || uploading}
                            className="flex-1 py-3 px-4 rounded-xl bg-[#E00D42] hover:bg-[#C20836] disabled:opacity-50 text-white font-bold text-xs shadow-xs transition flex items-center justify-center gap-2 cursor-pointer font-sans uppercase tracking-wider"
                        >
                            {uploading ? (
                                <span>Submitting...</span>
                            ) : (
                                <>
                                    <span>Submit ID</span>
                                    <ArrowRight className="w-4 h-4" />
                                </>
                            )}
                        </button>
                    </div>
                </form>

            </div>
        </div>
    );
}
