import { Head, Link } from '@inertiajs/react';
import { AlertCircle, ArrowLeft, LogIn, RotateCcw } from 'lucide-react';
import BagooLogo from '@/Components/BagooLogo';

interface Props {
    status: number;
    title: string;
    description: string;
    requestId: string | null;
    overviewUrl: string;
    loginUrl: string | null;
    retryUrl: string | null;
}

export default function LogisticsError({ title, description, requestId, overviewUrl, loginUrl, retryUrl }: Props) {
    const secondary =
        'inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-zinc-200/90 bg-white px-4 py-2 text-xs font-semibold text-zinc-700 shadow-2xs transition hover:bg-zinc-50 hover:text-[#121316] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42]';

    return (
        <>
            <Head title={`${title} — BagooPH Logistics`}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <main
                className="logistics-workspace flex min-h-screen items-center justify-center bg-[#F7F7F5] px-4 py-10 font-sans text-[#121316]"
                aria-labelledby="logistics-error-title"
            >
                <section className="w-full max-w-lg overflow-hidden rounded-xl border border-zinc-200/90 bg-white shadow-sm">
                    <div className="flex items-center justify-between border-b border-zinc-100 bg-zinc-50/50 px-6 py-4">
                        <div className="flex items-center gap-2.5">
                            <BagooLogo className="h-7 w-7" rounded="rounded-lg" />
                            <span className="text-xs font-bold tracking-tight text-[#121316]">BagooPH Logistics Workspace</span>
                        </div>
                        <span className="rounded-md border border-rose-200 bg-rose-50 px-2 py-0.5 text-[11px] font-semibold text-[#E00D42]">
                            Notice
                        </span>
                    </div>
                    <div className="p-6 sm:p-8">
                        <div className="flex h-10 w-10 items-center justify-center rounded-lg border border-rose-200 bg-rose-50 text-[#E00D42]">
                            <AlertCircle className="h-5 w-5" aria-hidden="true" />
                        </div>
                        <h1 id="logistics-error-title" className="mt-4 text-xl font-bold tracking-tight text-[#121316]">
                            {title}
                        </h1>
                        <p className="mt-2 text-sm leading-relaxed text-zinc-600">{description}</p>
                        <div className="mt-6 flex flex-col gap-2.5 sm:flex-row sm:flex-wrap">
                            {loginUrl && (
                                <Link href={loginUrl} className={secondary}>
                                    <LogIn className="h-3.5 w-3.5" aria-hidden="true" />
                                    Sign in again
                                </Link>
                            )}
                            {retryUrl && (
                                <Link href={retryUrl} className={secondary}>
                                    <RotateCcw className="h-3.5 w-3.5" aria-hidden="true" />
                                    Try again
                                </Link>
                            )}
                            <Link
                                href={overviewUrl}
                                className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-[#E00D42] px-4 py-2 text-xs font-semibold text-white shadow-xs transition hover:bg-[#C20836] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42]"
                            >
                                <ArrowLeft className="h-3.5 w-3.5" aria-hidden="true" />
                                Open Overview
                            </Link>
                        </div>
                        {requestId && (
                            <p className="mt-6 break-all border-t border-zinc-100 pt-4 font-mono text-[11px] text-zinc-400">
                                Reference: {requestId}
                            </p>
                        )}
                    </div>
                </section>
            </main>
        </>
    );
}
