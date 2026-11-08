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
        <div className="min-h-screen bg-zinc-100 text-zinc-900 print:bg-white print:text-black">
            <Head title="Logistics Workstation Command Sheet — BagooPH" />

            {/* Print Header Controls (Hidden on Print) */}
            <header className="sticky top-0 z-20 bg-white/95 backdrop-blur-xs border-b border-zinc-200 px-6 py-4 shadow-xs print:hidden">
                <div className="max-w-6xl mx-auto flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <a
                            href="/hub/scan"
                            className="inline-flex items-center gap-2 px-3.5 py-2 text-xs font-semibold text-zinc-700 bg-zinc-100 hover:bg-zinc-200/80 rounded-xl transition cursor-pointer"
                        >
                            <ArrowLeft className="h-4 w-4" />
                            Back to Scan Station
                        </a>
                        <span className="text-sm font-bold text-zinc-900">
                            Industrial Workstation Command Sheet
                        </span>
                    </div>

                    <button
                        onClick={handlePrint}
                        className="inline-flex items-center gap-2 px-4 py-2 text-xs font-bold text-white bg-[#E00D42] hover:bg-[#C20836] rounded-xl shadow-xs transition cursor-pointer"
                    >
                        <Printer className="h-4 w-4" />
                        Print Barcode Sheet (A4 / Letter)
                    </button>
                </div>
            </header>

            {/* Printable Document Body */}
            <main className="max-w-5xl mx-auto p-6 sm:p-10 print:p-0 print:max-w-full bg-white sm:my-8 sm:rounded-2xl sm:border sm:border-zinc-200/90 sm:shadow-xs print:border-none print:shadow-none print:my-0">
                {/* Title Banner */}
                <div className="border-b-2 border-zinc-900 pb-5 mb-8 flex items-end justify-between">
                    <div>
                        <div className="flex items-center gap-2 text-xs tabular-nums font-bold uppercase tracking-wider text-[#C20836]">
                            <Zap className="h-4 w-4 fill-current text-[#E00D42]" />
                            Bagoo Logistics Hub Operation Sheet
                        </div>
                        <h1 className="text-2xl sm:text-3xl font-black tracking-tight text-zinc-900 mt-1 uppercase font-sans">
                            Hardware Laser Command & Sort Sheet
                        </h1>
                        <p className="text-xs text-zinc-500 tabular-nums mt-1">
                            Standard 1D Code128 barcodes for hardware wedge barcode scanners and mobile camera intake.
                        </p>
                    </div>
                    <div className="text-right hidden sm:block print:block">
                        <span className="text-xs tabular-nums text-zinc-400 block">STANDARD ISSUE</span>
                        <span className="text-xs tabular-nums font-bold text-zinc-800">ISO/IEC 15417 COMPLIANT</span>
                    </div>
                </div>

                {/* Section 1: Workstation Command Barcodes */}
                <section className="mb-10">
                    <div className="flex items-center gap-2 mb-3">
                        <Zap className="h-5 w-5 text-[#E00D42]" />
                        <h2 className="text-lg font-black uppercase tracking-wide text-zinc-900 font-sans">
                            1. Operational Command Barcodes
                        </h2>
                    </div>
                    <p className="text-xs text-zinc-600 mb-5 tabular-nums">
                        Scan directly into the workstation input to trigger hardware macro actions without touching keyboard or mouse.
                    </p>

                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-6">
                        {commands.map((cmd) => (
                            <div
                                key={cmd.code}
                                className="bg-white border border-zinc-300 print:border-2 print:border-zinc-900 rounded-xl p-6 flex flex-col items-center justify-between text-center shadow-xs hover:border-zinc-400 hover:shadow-sm transition-all print:shadow-none print:break-inside-avoid"
                            >
                                <div className="mb-2">
                                    <span className="inline-block px-3 py-1 rounded-lg text-[11px] tabular-nums font-bold bg-[#E00D42] text-white uppercase tracking-wider">
                                        Command
                                    </span>
                                    <h3 className="text-base font-bold text-zinc-900 mt-2">
                                        {cmd.label}
                                    </h3>
                                    <p className="text-xs text-zinc-500 mt-1 max-w-[220px] leading-relaxed">
                                        {cmd.description}
                                    </p>
                                </div>

                                <div className="w-full flex flex-col items-center justify-center my-3 p-3 bg-zinc-50 print:bg-white rounded-xl border border-zinc-200 print:border-none">
                                    <Barcode
                                        value={cmd.code}
                                        width={2}
                                        height={55}
                                        displayValue={false}
                                        className="max-w-full"
                                    />
                                    <span className="text-xs tabular-nums font-black tracking-widest text-zinc-900 mt-2">
                                        *{cmd.code}*
                                    </span>
                                </div>
                            </div>
                        ))}
                    </div>
                </section>

                {/* Section 2: Standard Sorting Bins (LOC-BIN-01 through LOC-BIN-12) */}
                <section>
                    <div className="flex items-center gap-2 mb-3">
                        <Layers className="h-5 w-5 text-emerald-600" />
                        <h2 className="text-lg font-black uppercase tracking-wide text-zinc-900 font-sans">
                            2. Standard Sorting Bins & Staging Shelves
                        </h2>
                    </div>
                    <p className="text-xs text-zinc-600 mb-5 tabular-nums">
                        Poka-Yoke location barcodes. Scan during Two-Way Verification to confirm parcel placement into correct slot.
                    </p>

                    <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
                        {bins.map((bin) => (
                            <div
                                key={bin.code}
                                className="bg-white border border-zinc-300 print:border-2 print:border-zinc-800 rounded-xl p-4 flex flex-col items-center justify-between text-center shadow-xs hover:border-zinc-400 hover:shadow-sm transition-all print:shadow-none print:break-inside-avoid"
                            >
                                <div className="mb-1">
                                    <span className="text-[11px] tabular-nums font-black text-emerald-800 bg-emerald-50 px-2.5 py-1 rounded-lg border border-emerald-200 uppercase">
                                        {bin.label}
                                    </span>
                                </div>

                                <div className="w-full flex flex-col items-center justify-center my-2 p-2 bg-zinc-50 print:bg-white rounded-xl border border-zinc-200 print:border-none">
                                    <Barcode
                                        value={bin.code}
                                        width={1.6}
                                        height={42}
                                        displayValue={false}
                                        className="max-w-full"
                                    />
                                    <span className="text-[11px] tabular-nums font-black tracking-wider text-zinc-900 mt-1.5">
                                        *{bin.code}*
                                    </span>
                                </div>
                            </div>
                        ))}
                    </div>
                </section>

                {/* Footer notes */}
                <footer className="mt-12 pt-5 border-t border-zinc-200 text-center text-[11px] text-zinc-400 tabular-nums">
                    Bagoo Logistics Automation · Anti-Backhaul & Poka-Yoke Floor Verification · Form BL-WS-CMD-128
                </footer>
            </main>
        </div>
    );
}
