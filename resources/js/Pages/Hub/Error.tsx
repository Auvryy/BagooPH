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
    const secondary = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-800 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42]';

    return <>
        <Head title={`${title} — BagooPH Logistics`}><meta name="robots" content="noindex, nofollow" /></Head>
        <main className="flex min-h-screen items-center justify-center bg-slate-50 px-4 py-10 font-sans text-slate-900" aria-labelledby="logistics-error-title">
            <section className="w-full max-w-lg rounded-lg border border-slate-300 bg-white p-6 shadow-sm sm:p-8">
                <div className="flex items-center gap-3"><BagooLogo className="h-10 w-10" rounded="rounded-lg" /><span className="text-sm font-bold">BagooPH Logistics</span></div>
                <AlertCircle className="mt-8 h-7 w-7 text-[#E00D42]" aria-hidden="true" />
                <h1 id="logistics-error-title" className="mt-4 text-2xl font-bold tracking-tight">{title}</h1>
                <p className="mt-3 text-sm leading-6 text-slate-600">{description}</p>
                <div className="mt-6 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                    {loginUrl && <Link href={loginUrl} className={secondary}><LogIn className="h-4 w-4" aria-hidden="true" />Sign in again</Link>}
                    {retryUrl && <Link href={retryUrl} className={secondary}><RotateCcw className="h-4 w-4" aria-hidden="true" />Try again</Link>}
                    <Link href={overviewUrl} className="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-[#E00D42] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#C20836] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42]"><ArrowLeft className="h-4 w-4" aria-hidden="true" />Open Overview</Link>
                </div>
                {requestId && <p className="mt-6 break-all border-t border-slate-300 pt-4 text-xs leading-5 text-slate-500">Reference: {requestId}</p>}
            </section>
        </main>
    </>;
}
