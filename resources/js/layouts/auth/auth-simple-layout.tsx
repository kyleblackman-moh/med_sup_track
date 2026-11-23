import { AuthShell } from '@/layouts/auth/auth-shell';

interface AuthLayoutProps {
    children: React.ReactNode;
    name?: string;
    title?: string;
    description?: string;
}

/**
 * The other account pages (forgot and reset password, confirm, verify,
 * activation, the MFA challenge) in the sign-in page's shell: the Ministry's
 * identity panel on large screens, then the page's title, description and
 * its own content in the form column.
 */
export default function AuthSimpleLayout({ children, title, description }: AuthLayoutProps) {
    return (
        <AuthShell>
            <div className="space-y-1">
                <h1 className="text-2xl font-semibold capitalize">{title}</h1>
                {description && <p className="text-base-content/70 text-sm">{description}</p>}
            </div>
            <div className="mt-8">{children}</div>
        </AuthShell>
    );
}
