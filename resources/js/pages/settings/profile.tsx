import { type BreadcrumbItem, type SharedData } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Check, CircleCheck, MailWarning } from 'lucide-react';
import { FormEventHandler } from 'react';

import DeleteUser from '@/components/delete-user';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { cn } from '@/lib/utils';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Profile Settings',
        href: '/settings/profile',
    },
];

/**
 * Profile settings (Blueprint pages/profile-settings): the name and email the
 * Ministry uses for this account, in one bordered card that ends in its footer
 * row, then the delete-account card. daisyUI classes and theme tokens only.
 */
export default function Profile({ mustVerifyEmail, status }: { mustVerifyEmail: boolean; status?: string }) {
    const { auth } = usePage<SharedData>().props;

    const { data, setData, patch, errors, processing, recentlySuccessful } = useForm({
        name: auth.user.name,
        email: auth.user.email,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        patch(route('profile.update'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Profile Settings" />

            <SettingsLayout>
                <form onSubmit={submit} className="card bg-base-100 border-base-300 border">
                    <div className="card-body gap-4 p-4">
                        <HeadingSmall title="Profile Information" description="Update your name and email address" />

                        <fieldset className="fieldset gap-4 py-0">
                            <div className="grid gap-1.5">
                                <label htmlFor="name" className="label text-base-content text-sm font-medium capitalize">
                                    Name
                                </label>
                                <input
                                    id="name"
                                    className={cn('input input-sm w-full', errors.name && 'input-error')}
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    required
                                    autoComplete="name"
                                    placeholder="Full Name"
                                    aria-invalid={errors.name ? true : undefined}
                                    aria-describedby={errors.name ? 'name-error' : undefined}
                                />
                                <InputError id="name-error" message={errors.name} />
                            </div>

                            <div className="grid gap-1.5">
                                <label htmlFor="email" className="label text-base-content text-sm font-medium capitalize">
                                    Email Address
                                </label>
                                <input
                                    id="email"
                                    type="email"
                                    className={cn('input input-sm w-full', errors.email && 'input-error')}
                                    value={data.email}
                                    onChange={(e) => setData('email', e.target.value)}
                                    required
                                    autoComplete="username"
                                    placeholder="Email Address"
                                    aria-invalid={errors.email ? true : undefined}
                                    aria-describedby={errors.email ? 'email-error' : undefined}
                                />
                                <InputError id="email-error" message={errors.email} />
                            </div>
                        </fieldset>

                        {mustVerifyEmail && auth.user.email_verified_at === null && (
                            <div className="grid gap-2">
                                <div role="alert" className="alert alert-warning alert-soft text-sm">
                                    <MailWarning className="size-4 shrink-0" aria-hidden="true" />
                                    <p>
                                        Your email address is unverified.{' '}
                                        <Link href={route('verification.send')} method="post" as="button" className="link">
                                            Click here to re-send the verification email.
                                        </Link>
                                    </p>
                                </div>

                                {status === 'verification-link-sent' && (
                                    <div role="status" className="alert alert-success alert-soft text-sm">
                                        <CircleCheck className="size-4 shrink-0" aria-hidden="true" />
                                        <span>A new verification link has been sent to your email address.</span>
                                    </div>
                                )}
                            </div>
                        )}

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
                                Save
                            </button>
                        </div>
                    </div>
                </form>

                <DeleteUser />
            </SettingsLayout>
        </AppLayout>
    );
}
