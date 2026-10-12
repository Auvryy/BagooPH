import { Head } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import type { RegistrationPolicies } from '@/Components/RegistrationPolicyAcknowledgement';

export default function Policy({ document, policies }: {
    document: { title: string; version: string; introduction: string; sections: { title: string; paragraphs: string[] }[] };
    policies: RegistrationPolicies;
}) {
    return <GuestLayout title={document.title} subtitle={`Version ${document.version}`} maxWidth="3xl">
        <Head title={`${document.title} — BagooPH`} />
        <article className="space-y-6 text-sm leading-relaxed text-slate-700">
            <p>{document.introduction}</p>
            {document.sections.map(section => <section key={section.title} className="space-y-2">
                <h2 className="text-base font-bold text-slate-900">{section.title}</h2>
                {section.paragraphs.map(paragraph => <p key={paragraph}>{paragraph}</p>)}
            </section>)}
        </article>
        <nav aria-label="Account policies" className="mt-6 flex flex-wrap gap-4 border-t border-slate-300 pt-4 text-sm">
            <a className="font-semibold underline" href={policies.terms_url}>Terms of Use</a>
            <a className="font-semibold underline" href={policies.privacy_url}>Privacy Notice</a>
            <a className="font-semibold underline" href="/login">Sign in</a>
        </nav>
    </GuestLayout>;
}
