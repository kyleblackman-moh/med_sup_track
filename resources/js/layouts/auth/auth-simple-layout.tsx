import { AuthShell } from '@/layouts/auth/auth-shell';

interface AuthLayoutProps {
    children: React.ReactNode;
    name?: string;
    title?: string;
    description?: string;
}

/**
 * The other account pages (forgot and reset password, confirm, verify,
 * activation, the MFA challenge) in the sign-in page's shell: the centred
 * card with the page's title and description, then its own content.
 */
export default function AuthSimpleLayout({ children, title, description }: AuthLayoutProps) {
    return (
        <AuthShell>
            <div className="space-y-1 text-center">
                <h1 className="text-xl font-semibold capitalize">{title}</h1>
                {description && <p className="text-base-content/70 text-sm">{description}</p>}
            </div>
            <div className="mt-6">{children}</div>
        </AuthShell>
    );
}
