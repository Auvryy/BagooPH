import React from 'react';
import { Head } from '@inertiajs/react';
import Barcode from '@/Components/Barcode';
import { Printer, ArrowLeft, Zap, Layers } from 'lucide-react';

interface CommandItem {
    code: string;
    label: string;
    description: string;
}

interface BinItem {
    code: string;
    label: string;
    description: string;
}

interface Props {
    commands: CommandItem[];
    bins: BinItem[];
}

export default function CommandSheet({ commands, bins }: Props) {
    const handlePrint = () => {
        window.print();
    };

    return (
        <div className="min-h-screen bg-slate-50 text-slate-900 print:bg-white print:text-black">
            <Head title="Logistics Workstation Command Sheet" />

            {/* Print Header Controls (Hidden on Print) */}
            <header className="sticky top-0 z-20 bg-white border-b border-slate-200 px-6 py-4 shadow-xs print:hidden">
                <div className="max-w-6xl mx-auto flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <a
                            href="/hub/scan"
                            className="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors"
                        >
                            <ArrowLeft className="h-4 w-4" />
                            Back to Scan Station
                        </a>
                        <span className="text-sm font-bold text-slate-900">
                            Industrial Workstation Command Sheet
                        </span>
                    </div>

                    <button
                        onClick={handlePrint}
                        className="inline-flex items-center gap-2 px-4 py-2 text-sm font-bold text-white bg-slate-900 hover:bg-slate-800 rounded-lg shadow-sm transition-colors cursor-pointer"
                    >
                        <Printer className="h-4 w-4" />
                        Print Barcode Sheet (A4 / Letter)
                    </button>
                </div>
            </header>

            {/* Printable Document Body */}
            <main className="max-w-5xl mx-auto p-6 sm:p-10 print:p-0 print:max-w-full">
                {/* Title Banner */}
                <div className="border-b-2 border-slate-900 pb-4 mb-8 flex items-end justify-between">
                    <div>
                        <div className="flex items-center gap-2 text-xs font-mono font-bold uppercase tracking-wider text-amber-600">
                            <Zap className="h-4 w-4 fill-current" />
                            Bagoo Logistics Hub Operation Sheet
                        </div>
                        <h1 className="text-2xl sm:text-3xl font-black tracking-tight text-slate-900 mt-1 uppercase">
                            Hardware Laser Command & Sort Sheet
                        </h1>
                        <p className="text-xs text-slate-600 font-mono mt-1">
                            Standard 1D Code128 barcodes for hardware wedge barcode scanners and mobile camera intake.
                        </p>
                    </div>
                    <div className="text-right hidden sm:block print:block">
                        <span className="text-xs font-mono text-slate-500 block">STANDARD ISSUE</span>
                        <span className="text-xs font-mono font-bold text-slate-800">ISO/IEC 15417 COMPLIANT</span>
                    </div>
                </div>

                {/* Section 1: Workstation Command Barcodes */}
                <section className="mb-10">
                    <div className="flex items-center gap-2 mb-4">
                        <Zap className="h-5 w-5 text-indigo-600" />
                        <h2 className="text-lg font-black uppercase tracking-wide text-slate-900">
                            1. Operational Command Barcodes
                        </h2>
                    </div>
                    <p className="text-xs text-slate-600 mb-4 font-mono">
                        Scan directly into the workstation input to trigger hardware macro actions without touching keyboard or mouse.
                    </p>

                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-6">
                        {commands.map((cmd) => (
                            <div
                                key={cmd.code}
                                className="bg-white border-2 border-slate-900 rounded-xl p-5 flex flex-col items-center justify-between text-center shadow-xs print:shadow-none print:break-inside-avoid"
                            >
                                <div className="mb-2">
                                    <span className="inline-block px-2.5 py-0.5 rounded text-[11px] font-mono font-bold bg-slate-900 text-white uppercase tracking-wider">
                                        Command
                                    </span>
                                    <h3 className="text-base font-bold text-slate-900 mt-1">
                                        {cmd.label}
                                    </h3>
                                    <p className="text-[11px] text-slate-500 mt-0.5 max-w-[220px]">
                                        {cmd.description}
                                    </p>
                                </div>

                                <div className="w-full flex flex-col items-center justify-center my-2 p-2 bg-slate-50 print:bg-white rounded border border-slate-200 print:border-none">
                                    <Barcode
                                        value={cmd.code}
                                        width={2}
                                        height={55}
                                        displayValue={false}
                                        className="max-w-full"
                                    />
                                    <span className="text-xs font-mono font-black tracking-widest text-slate-900 mt-2">
                                        *{cmd.code}*
                                    </span>
                                </div>
                            </div>
                        ))}
                    </div>
                </section>

                {/* Section 2: Standard Sorting Bins (LOC-BIN-01 through LOC-BIN-12) */}
                <section>
                    <div className="flex items-center gap-2 mb-4">
                        <Layers className="h-5 w-5 text-emerald-600" />
                        <h2 className="text-lg font-black uppercase tracking-wide text-slate-900">
                            2. Standard Sorting Bins & Staging Shelves
                        </h2>
                    </div>
                    <p className="text-xs text-slate-600 mb-4 font-mono">
                        Poka-Yoke location barcodes. Scan during Two-Way Verification to confirm parcel placement into correct slot.
                    </p>

                    <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
                        {bins.map((bin) => (
                            <div
                                key={bin.code}
                                className="bg-white border-2 border-slate-800 rounded-lg p-3 flex flex-col items-center justify-between text-center shadow-xs print:shadow-none print:break-inside-avoid"
                            >
                                <div className="mb-1">
                                    <span className="text-[11px] font-mono font-black text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200 uppercase">
                                        {bin.label}
                                    </span>
                                </div>

                                <div className="w-full flex flex-col items-center justify-center my-1.5 p-1.5 bg-slate-50 print:bg-white rounded border border-slate-200 print:border-none">
                                    <Barcode
                                        value={bin.code}
                                        width={1.6}
                                        height={42}
                                        displayValue={false}
                                        className="max-w-full"
                                    />
                                    <span className="text-[11px] font-mono font-black tracking-wider text-slate-900 mt-1">
                                        *{bin.code}*
                                    </span>
                                </div>
                            </div>
                        ))}
                    </div>
                </section>

                {/* Footer notes */}
                <footer className="mt-12 pt-4 border-t border-slate-300 text-center text-[10px] text-slate-500 font-mono">
                    Bagoo Logistics Automation · Anti-Backhaul & Poka-Yoke Floor Verification · Form BL-WS-CMD-128
                </footer>
            </main>
        </div>
    );
}
