import { usePage } from '@inertiajs/react';
import IdentityCorrectionPanel, { type IdentityCorrectionProps } from './IdentityCorrectionPanel';
import { type PageProps } from '@/types';

export default function AccountIdentitySettings({ theme = 'default' }: { theme?: IdentityCorrectionProps['theme'] }) {
    const { identityCorrection } = usePage<PageProps<{ identityCorrection?: Pick<IdentityCorrectionProps, 'subject' | 'categories'> | null }>>().props;
    return identityCorrection ? <IdentityCorrectionPanel {...identityCorrection} theme={theme} /> : null;
}
