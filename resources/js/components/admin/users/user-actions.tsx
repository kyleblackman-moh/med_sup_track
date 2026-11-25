import InputError from '@/components/input-error';
import { router, useForm, usePage } from '@inertiajs/react';
import { Ban, IdCard, Mail, RotateCcw, ShieldOff, UserCog } from 'lucide-react';
import { type ReactNode, useState } from 'react';
import { ReasonDialog } from './reason-dialog';
import { type AdminUserDetail, type RoleOption, type UserAbilities } from './types';

/** A titled card whose buttons sit in a footer row at the bottom (CLAUDE.md). */
function Panel({
    id,
    title,
    icon,
    description,
    footer,
    children,
}: {
    id: string;
    title: string;
    icon: ReactNode;
    description: ReactNode;
    footer: ReactNode;
    children?: ReactNode;
}) {
    return (
        <section aria-labelledby={id} className="card bg-base-100 border-base-300 border">
            <div className="card-body gap-3 p-4 md:p-6">
                <h2 id={id} className="card-title text-base capitalize">
                    {icon}
                    {title}
                </h2>
                <div className="text-base-content/70 text-helper">{description}</div>
                {children}
            </div>
            <div className="card-actions border-base-300 bg-base-200 rounded-b-box justify-end border-t px-4 py-3 md:px-6">{footer}</div>
        </section>
    );
}

/** Backlog, 2026-11-25: the job title and division printed under the user's name on the request letter. */
export function DetailsForm({ user }: { user: AdminUserDetail }) {
    const initial = { job_title: user.job_title ?? '', division: user.division ?? '' };
    const form = useForm(initial);
    const changed = form.data.job_title !== initial.job_title || form.data.division !== initial.division;

    const field = (key: 'job_title' | 'division', label: string, max: number) => (
        <fieldset className="fieldset py-0">
            <label htmlFor={`user-${key}`} className="label text-base-content text-sm font-medium capitalize">
                {label}
            </label>
            <input
                id={`user-${key}`}
                aria-label={label}
                className="input input-sm w-full"
                value={form.data[key]}
                maxLength={max}
                autoComplete="off"
                onChange={(e) => form.setData(key, e.target.value)}
                aria-invalid={form.errors[key] ? true : undefined}
                aria-describedby={form.errors[key] ? `user-${key}-error` : undefined}
            />
            <InputError id={`user-${key}-error`} message={form.errors[key]} />
        </fieldset>
    );

    return (
        <Panel
            id="user-details-form-heading"
            title="Letter Sign-Off"
            icon={<IdCard className="size-5" aria-hidden="true" />}
            description="Printed under the user's name when they sign a request letter."
            footer={
                <>
                    <button
                        type="button"
                        className="btn btn-sm capitalize"
                        disabled={!changed || form.processing}
                        onClick={() => form.setData(initial)}
                    >
                        Reset
                    </button>
                    <button
                        type="button"
                        className="btn btn-primary btn-sm capitalize"
                        disabled={!changed || form.processing}
                        onClick={() => form.put(route('admin.users.details', user.id), { preserveScroll: true })}
                    >
                        {form.processing ? 'Saving…' : 'Save Details'}
                    </button>
                </>
            }
        >
            <div className="grid max-w-xl gap-3 sm:grid-cols-2">
                {field('job_title', 'Job Title', 255)}
                {field('division', 'Division', 150)}
            </div>
        </Panel>
    );
}

/** IT-ADMIN: change the role (same kind of account only; the options are the server's). Saving asks for the reason. */
export function RoleForm({ user, options, reasonMax }: { user: AdminUserDetail; options: RoleOption[]; reasonMax: number }) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [role, setRole] = useState(user.role_value ?? '');
    const [confirming, setConfirming] = useState(false);
    const chosen = options.find((r) => r.value === role);
    const changed = role !== '' && role !== (user.role_value ?? '');

    return (
        <Panel
            id="user-role-heading"
            title="Role"
            icon={<UserCog className="size-5" aria-hidden="true" />}
            description="A new role takes effect from the user's next sign-in. They are signed out now and get a security email."
            footer={
                <>
                    <button type="button" className="btn btn-sm capitalize" disabled={!changed} onClick={() => setRole(user.role_value ?? '')}>
                        Reset
                    </button>
                    <button type="button" className="btn btn-primary btn-sm capitalize" disabled={!changed} onClick={() => setConfirming(true)}>
                        Save Role
                    </button>
                </>
            }
        >
            <fieldset className="fieldset max-w-md py-0">
                <label htmlFor="role" className="label text-base-content text-sm font-medium capitalize">
                    Role
                </label>
                <select
                    id="role"
                    className="select select-sm w-full"
                    value={role}
                    onChange={(e) => setRole(e.target.value)}
                    aria-required
                    aria-invalid={errors.role ? true : undefined}
                    aria-describedby={errors.role ? 'role-error' : undefined}
                >
                    <option value="" disabled>
                        Choose a role
                    </option>
                    {options.map((r) => (
                        <option key={r.value} value={r.value}>
                            {r.label}
                        </option>
                    ))}
                </select>
                <InputError id="role-error" message={errors.role} />
            </fieldset>

            <ReasonDialog
                open={confirming}
                onClose={() => setConfirming(false)}
                title={`Change ${user.name}'s role?`}
                description={
                    <>
                        From <span className="font-medium">{user.role}</span> to <span className="font-medium">{chosen?.label ?? role}</span>.
                    </>
                }
                warning="They are signed out of every session now and have the new role from their next sign-in."
                action={{ route: 'admin.users.role', userId: user.id, method: 'put', extra: { role } }}
                confirmLabel="Change Role"
                busyLabel="Saving…"
                reasonMax={reasonMax}
                reasonRequired
            />
        </Panel>
    );
}

type AccessDialog = 'disable' | 'enable' | null;

/** IT-ADMIN: disable or re-enable the account (each with a reason), and resend the activation link. */
export function AccessActions({ user, abilities, reasonMax }: { user: AdminUserDetail; abilities: UserAbilities; reasonMax: number }) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [dialog, setDialog] = useState<AccessDialog>(null);
    const [sending, setSending] = useState(false);

    if (!abilities.disable && !abilities.enable && !abilities.resend_activation) {
        return null;
    }

    const resend = () =>
        router.post(
            route('admin.users.activation', user.id),
            {},
            { preserveScroll: true, onStart: () => setSending(true), onFinish: () => setSending(false) },
        );

    const description = user.is_pending
        ? 'This user has not activated their account yet. A new activation link replaces the old one, which stops working.'
        : user.is_active
          ? 'Disabling signs the user out at once and stops them signing in. Nothing they did is removed, and the account can be enabled again.'
          : 'This user cannot sign in. Enabling lets them sign in again with their existing password and MFA.';

    return (
        <Panel
            id="user-access-heading"
            title="Access"
            icon={<Ban className="size-5" aria-hidden="true" />}
            description={description}
            footer={
                <>
                    {abilities.resend_activation && (
                        <button type="button" className="btn btn-sm capitalize" disabled={sending} aria-busy={sending || undefined} onClick={resend}>
                            <Mail className="size-4" aria-hidden="true" />
                            {sending ? 'Sending…' : 'Resend Activation Link'}
                        </button>
                    )}
                    {abilities.enable && (
                        <button type="button" className="btn btn-primary btn-sm capitalize" onClick={() => setDialog('enable')}>
                            <RotateCcw className="size-4" aria-hidden="true" />
                            Enable User
                        </button>
                    )}
                    {abilities.disable && (
                        <button type="button" className="btn btn-error btn-outline btn-sm capitalize" onClick={() => setDialog('disable')}>
                            <Ban className="size-4" aria-hidden="true" />
                            Disable User
                        </button>
                    )}
                </>
            }
        >
            <InputError message={errors.account} role="alert" />

            <ReasonDialog
                open={dialog === 'disable'}
                onClose={() => setDialog(null)}
                title={`Disable ${user.name}?`}
                description={user.email}
                warning="They are signed out of every session now and cannot sign in until the account is enabled again."
                destructive
                action={{ route: 'admin.users.disable', userId: user.id, method: 'post' }}
                confirmLabel="Disable User"
                busyLabel="Disabling…"
                reasonMax={reasonMax}
                reasonRequired
            />
            <ReasonDialog
                open={dialog === 'enable'}
                onClose={() => setDialog(null)}
                title={`Enable ${user.name}?`}
                description={user.email}
                action={{ route: 'admin.users.enable', userId: user.id, method: 'post' }}
                confirmLabel="Enable User"
                busyLabel="Enabling…"
                reasonMax={reasonMax}
                reasonRequired
            >
                <p className="text-sm">
                    They can sign in again with their existing password. If they never activated the account, send them a new activation link
                    afterwards.
                </p>
            </ReasonDialog>
        </Panel>
    );
}

/** IT-ADMIN: the user's MFA status and, when the server allows it, the reset (irreversible; reason optional). */
export function MfaPanel({ user, canReset, reasonMax }: { user: AdminUserDetail; canReset: boolean; reasonMax: number }) {
    const [confirming, setConfirming] = useState(false);

    if (!canReset) {
        return null;
    }

    return (
        <Panel
            id="user-mfa-heading"
            title="Multi-Factor Authentication"
            icon={<ShieldOff className="size-5" aria-hidden="true" />}
            description="Reset MFA when the user has lost their authenticator and their recovery codes."
            footer={
                <button type="button" className="btn btn-error btn-outline btn-sm capitalize" onClick={() => setConfirming(true)}>
                    <ShieldOff className="size-4" aria-hidden="true" />
                    Reset MFA
                </button>
            }
        >
            <ReasonDialog
                open={confirming}
                onClose={() => setConfirming(false)}
                title={`Reset MFA for ${user.name}?`}
                description={user.email}
                destructive
                warning={
                    <>
                        <span className="font-semibold">This cannot be undone.</span> Their authenticator and recovery codes stop working, every
                        session ends, and they must set up MFA again at their next sign-in.
                    </>
                }
                action={{ route: 'admin.users.mfa-reset', userId: user.id, method: 'post' }}
                confirmLabel="Reset MFA"
                busyLabel="Resetting…"
                reasonMax={reasonMax}
                reasonRequired={false}
            />
        </Panel>
    );
}
