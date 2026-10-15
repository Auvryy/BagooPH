import { usePage } from '@inertiajs/react';
import { PageProps } from '@/types';
import NotificationMenu from './NotificationMenu';

export default function NotificationLink() {
    const { auth } = usePage<PageProps>().props;
    if (!auth.user) return null;
    return <NotificationMenu key={auth.user.id} />;
}
