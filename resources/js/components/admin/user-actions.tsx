import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type AdminUserDetail, type RoleOption } from '@/types/admin';
import { router, useForm, usePage } from '@inertiajs/react';
import { Ban, Mail, RotateCcw } from 'lucide-react';
import { type FormEventHandler, useRef, useState } from 'react';

/** Page-level hints from the server (UserController::show); every endpoint authorises itself. */
export interface UserAbilities {
    change_role: boolean;
    disable: boolean;
    enable: boolean;
    resend_activation: boolean;
}

/** ADMIN-1 Step 3: change the role. The options are the server's (same kind of account only). */
export function RoleForm({ user, options }: { user: AdminUserDetail; options: RoleOption[] }) {
    const { data, setData, put, processing, errors, isDirty } = useForm({ role: user.role_value ?? '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('admin.users.role', user.id), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} noValidate aria-labelledby="user-role-heading" className="bg-card rounded-xl border shadow-xs">
            <div className="grid gap-4 p-5 md:p-6">
                <h2 id="user-role-heading" className="text-base font-semibold">
                    Role
                </h2>
                <div className="grid max-w-md gap-2">
                    <Label htmlFor="role">Role</Label>
                    <Select value={data.role} onValueChange={(v) => setData('role', v)}>
                        <SelectTrigger
                            id="role"
                            aria-required
                            aria-invalid={errors.role ? true : undefined}
                            aria-describedby={errors.role ? 'role-error' : undefined}
                            className="border-input bg-card w-full"
                        >
                            <SelectValue placeholder="Choose a role" />
                        </SelectTrigger>
                        <SelectContent>
                            {options.map((r) => (
                                <SelectItem key={r.value} value={r.value}>
                                    {r.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError id="role-error" message={errors.role} />
                </div>
            </div>
            <div className="bg-subtle flex justify-end rounded-b-xl border-t px-5 py-4 md:px-6">
                <Button type="submit" disabled={processing || !isDirty} aria-busy={processing} className="h-11 md:h-10">
                    {processing ? 'Saving…' : 'Save Role'}
                </Button>
            </div>
        </form>
    );
}

/** ADMIN-1 Step 3: disable or enable the account, and resend the sign-in link. */
export function AccessActions({ user, abilities }: { user: AdminUserDetail; abilities: UserAbilities }) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState<string | null>(null);
    const cancelRef = useRef<HTMLButtonElement>(null);

    const post = (name: 'admin.users.disable' | 'admin.users.enable' | 'admin.users.activation') =>
        router.post(
            route(name, user.id),
            {},
            {
                preserveScroll: true,
                onStart: () => setBusy(name),
                onFinish: () => {
                    setBusy(null);
                    setConfirming(false);
                },
            },
        );

    if (!abilities.disable && !abilities.enable && !abilities.resend_activation) {
        return null;
    }

    return (
        <section aria-labelledby="user-access-heading" className="bg-card rounded-xl border p-5 shadow-xs md:p-6">
            <h2 id="user-access-heading" className="mb-1 text-base font-semibold">
                Access
            </h2>
            <p className="text-muted-foreground mb-4 text-[13px]">
                {user.is_active
                    ? 'Disabling signs the user out at once and stops them signing in. Nothing they did is removed.'
                    : 'This user cannot sign in. Enabling lets them sign in again with their existing password.'}
            </p>
            <InputError message={errors.account} className="mb-3" role="alert" />
            <div className="flex flex-wrap gap-2">
                {abilities.resend_activation && (
                    <Button variant="outline" className="h-11 md:h-10" disabled={busy !== null} onClick={() => post('admin.users.activation')}>
                        <Mail aria-hidden="true" />
                        {busy === 'admin.users.activation' ? 'Sending…' : 'Resend Sign-In Link'}
                    </Button>
                )}
                {abilities.enable && (
                    <Button variant="outline" className="h-11 md:h-10" disabled={busy !== null} onClick={() => post('admin.users.enable')}>
                        <RotateCcw aria-hidden="true" />
                        {busy === 'admin.users.enable' ? 'Enabling…' : 'Enable User'}
                    </Button>
                )}
                {abilities.disable && (
                    <Button
                        variant="outline"
                        className="border-destructive text-destructive-text hover:bg-destructive/10 hover:text-destructive-text h-11 md:h-10"
                        disabled={busy !== null}
                        onClick={() => setConfirming(true)}
                    >
                        <Ban aria-hidden="true" />
                        Disable User
                    </Button>
                )}
            </div>

            <Dialog open={confirming} onOpenChange={(open) => !open && busy === null && setConfirming(false)}>
                <DialogContent
                    onOpenAutoFocus={(e) => {
                        e.preventDefault();
                        cancelRef.current?.focus();
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>Disable {user.name}?</DialogTitle>
                        <DialogDescription>
                            They will be signed out now and will not be able to sign in until the account is enabled again.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            ref={cancelRef}
                            type="button"
                            variant="outline"
                            className="h-11 md:h-9"
                            onClick={() => setConfirming(false)}
                            disabled={busy !== null}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            className="h-11 md:h-9"
                            onClick={() => post('admin.users.disable')}
                            disabled={busy !== null}
                        >
                            {busy === 'admin.users.disable' ? 'Disabling…' : 'Disable User'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}
