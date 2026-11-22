import { useForm } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { FormEventHandler, useRef } from 'react';

import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';

/**
 * Delete account (Blueprint pages/profile-settings, destructive account action):
 * a bordered card with an error alert and the trigger in its footer row; the
 * daisyUI modal (native dialog) asks for the password before the delete.
 */
export default function DeleteUser() {
    const passwordInput = useRef<HTMLInputElement>(null);
    const { data, setData, delete: destroy, processing, reset, errors, clearErrors } = useForm({ password: '' });

    const deleteUser: FormEventHandler = (e) => {
        e.preventDefault();

        destroy(route('profile.destroy'), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
            onError: () => passwordInput.current?.focus(),
            onFinish: () => reset(),
        });
    };

    const closeModal = () => {
        clearErrors();
        reset();
    };

    return (
        <section aria-label="Delete Account" className="card bg-base-100 border-base-300 border">
            <div className="card-body gap-4 p-4">
                <HeadingSmall title="Delete Account" description="Delete your account and all of its resources" />

                <div role="alert" className="alert alert-error alert-soft items-start text-sm">
                    <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <div>
                        <p className="font-medium capitalize">Warning</p>
                        <p>Please proceed with caution, this cannot be undone.</p>
                    </div>
                </div>

                <Dialog>
                    <div className="card-actions border-base-300 -mx-4 mt-1 -mb-1 items-center justify-end border-t px-4 pt-3">
                        <DialogTrigger className="btn btn-error btn-sm capitalize">Delete Account</DialogTrigger>
                    </div>
                    <DialogContent>
                        <DialogTitle>Are you sure you want to delete your account?</DialogTitle>
                        <DialogDescription>
                            Once your account is deleted, all of its resources and data will also be permanently deleted. Please enter your password
                            to confirm you would like to permanently delete your account.
                        </DialogDescription>
                        <form className="grid gap-4" onSubmit={deleteUser}>
                            <fieldset className="fieldset gap-1.5 py-0">
                                <label htmlFor="password" className="label sr-only">
                                    Password
                                </label>
                                <input
                                    id="password"
                                    aria-required="true"
                                    type="password"
                                    name="password"
                                    ref={passwordInput}
                                    value={data.password}
                                    onChange={(e) => setData('password', e.target.value)}
                                    placeholder="Password"
                                    autoComplete="current-password"
                                    className={cn('input input-sm w-full', errors.password && 'input-error')}
                                    aria-invalid={errors.password ? true : undefined}
                                    aria-describedby={errors.password ? 'delete-password-error' : undefined}
                                />
                                <InputError id="delete-password-error" message={errors.password} />
                            </fieldset>

                            <DialogFooter>
                                <DialogClose className="btn btn-sm capitalize" onClick={closeModal}>
                                    Cancel
                                </DialogClose>

                                <button type="submit" className="btn btn-error btn-sm capitalize" disabled={processing}>
                                    {processing && <span className="loading loading-spinner loading-xs" aria-hidden="true" />}
                                    Delete Account
                                </button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>
        </section>
    );
}
