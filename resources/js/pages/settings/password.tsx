import InputError from '@/components/input-error';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, useForm } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { FormEventHandler, useRef } from 'react';

import HeadingSmall from '@/components/heading-small';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Password Settings',
        href: '/settings/password',
    },
];

/** Shared field classes: the daisyUI input, red when the server rejects it. */
const fieldClass = (invalid: boolean) => cn('input input-sm w-full', invalid && 'input-error');

/**
 * Password settings (Blueprint pages/security-settings): change the password
 * in one bordered card that ends in its footer row. Also the page a user with
 * a forced password change lands on. daisyUI classes and theme tokens only.
 */
export default function Password() {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    const { data, setData, errors, put, reset, processing, recentlySuccessful } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const updatePassword: FormEventHandler = (e) => {
        e.preventDefault();

        put(route('password.update'), {
            preserveScroll: true,
            onSuccess: () => reset(),
            onError: (errors) => {
                if (errors.password) {
                    reset('password', 'password_confirmation');
                    passwordInput.current?.focus();
                }

                if (errors.current_password) {
                    reset('current_password');
                    currentPasswordInput.current?.focus();
                }
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Profile Settings" />

            <SettingsLayout>
                <form onSubmit={updatePassword} className="card bg-base-100 border-base-300 border">
                    <div className="card-body gap-4 p-4">
                        <HeadingSmall title="Update Password" description="Ensure your account is using a long, random password to stay secure" />

                        <fieldset className="fieldset gap-4 py-0">
                            <div className="grid gap-1.5">
                                <label htmlFor="current_password" className="label text-base-content text-sm font-medium capitalize">
                                    Current Password
                                </label>
                                <input
                                    id="current_password"
                                    aria-required="true"
                                    ref={currentPasswordInput}
                                    value={data.current_password}
                                    onChange={(e) => setData('current_password', e.target.value)}
                                    type="password"
                                    className={fieldClass(!!errors.current_password)}
                                    autoComplete="current-password"
                                    placeholder="Current Password"
                                    aria-invalid={errors.current_password ? true : undefined}
                                    aria-describedby={errors.current_password ? 'current_password-error' : undefined}
                                />
                                <InputError id="current_password-error" message={errors.current_password} />
                            </div>

                            <div className="grid gap-1.5">
                                <label htmlFor="password" className="label text-base-content text-sm font-medium capitalize">
                                    New Password
                                </label>
                                <input
                                    id="password"
                                    aria-required="true"
                                    ref={passwordInput}
                                    value={data.password}
                                    onChange={(e) => setData('password', e.target.value)}
                                    type="password"
                                    className={fieldClass(!!errors.password)}
                                    autoComplete="new-password"
                                    placeholder="New Password"
                                    aria-invalid={errors.password ? true : undefined}
                                    aria-describedby={errors.password ? 'password-error' : undefined}
                                />
                                <InputError id="password-error" message={errors.password} />
                            </div>

                            <div className="grid gap-1.5">
                                <label htmlFor="password_confirmation" className="label text-base-content text-sm font-medium capitalize">
                                    Confirm Password
                                </label>
                                <input
                                    id="password_confirmation"
                                    aria-required="true"
                                    value={data.password_confirmation}
                                    onChange={(e) => setData('password_confirmation', e.target.value)}
                                    type="password"
                                    className={fieldClass(!!errors.password_confirmation)}
                                    autoComplete="new-password"
                                    placeholder="Confirm Password"
                                    aria-invalid={errors.password_confirmation ? true : undefined}
                                    aria-describedby={errors.password_confirmation ? 'password_confirmation-error' : undefined}
                                />
                                <InputError id="password_confirmation-error" message={errors.password_confirmation} />
                            </div>
                        </fieldset>

                        <div className="card-actions border-base-300 -mx-4 mt-1 -mb-1 items-center justify-end border-t px-4 pt-3">
                            <Transition
                                show={recentlySuccessful}
                                enter="motion-safe:transition-opacity"
                                enterFrom="opacity-0"
                                leave="motion-safe:transition-opacity"
                                leaveTo="opacity-0"
                            >
                                <p role="status" className="text-success flex items-center gap-1 text-sm">
                                    <Check className="size-4" aria-hidden="true" />
                                    Saved
                                </p>
                            </Transition>
                            <button type="submit" className="btn btn-primary btn-sm capitalize" disabled={processing}>
                                {processing && <span className="loading loading-spinner loading-xs" aria-hidden="true" />}
                                Save Password
                            </button>
                        </div>
                    </div>
                </form>
            </SettingsLayout>
        </AppLayout>
    );
}
