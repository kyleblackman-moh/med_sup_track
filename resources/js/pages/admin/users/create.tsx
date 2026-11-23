import { type RoleOption } from '@/components/admin/users/types';
import InputError from '@/components/input-error';
import { PageContent, PageHeader } from '@/components/page-header';
import { FlashMessages } from '@/components/requests/flash-messages';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type OptionList } from '@/types/requests';
import { Head, Link, useForm } from '@inertiajs/react';
import { Mail } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface Props {
    /** Only the roles this user may give (UserController::create); organisations only when they may invite vendor users. */
    options: { roles: RoleOption[]; organisations: OptionList };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Users', href: '/admin/users' },
    { title: 'New User', href: '/admin/users/create' },
];

const LABEL = 'label text-base-content text-sm font-medium capitalize';

/**
 * IT-ADMIN: create a staff account or invite a vendor user. The roles are
 * the ones the server lets this user give; the Organisation field shows only
 * for a role the server marks `vendor` (staff join the Ministry of Health on
 * the server). No password is entered or shown: the account waits for
 * activation and the person is emailed a single-use link to set their
 * password and then MFA. The server validates the role and organisation pairing.
 */
export default function AdminUserCreate({ options }: Props) {
    const { data, setData, post, processing, errors } = useForm({ name: '', email: '', role: '', organisation: '' });

    const needsOrganisation = options.roles.find((r) => r.value === data.role)?.vendor ?? false;

    const chooseRole = (role: string) => {
        const vendor = options.roles.find((r) => r.value === role)?.vendor ?? false;
        setData((current) => ({ ...current, role, organisation: vendor ? current.organisation : '' }));
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('admin.users.store'));
    };

    const describedBy = (field: keyof typeof errors) => (errors[field] ? `${field}-error` : undefined);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New User" />

            <PageContent>
                <PageHeader title="New User" subtitle="They get an email with a single-use link to set their password, then set up MFA at sign-in." />

                <FlashMessages />

                <form onSubmit={submit} noValidate className="card bg-base-100 border-base-300 border">
                    <div className="card-body grid gap-1 p-4 md:p-6 lg:grid-cols-2 lg:gap-x-6">
                        <fieldset className="fieldset py-0">
                            <label htmlFor="name" className={LABEL}>
                                Name
                            </label>
                            <input
                                id="name"
                                className="input input-sm w-full"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                required
                                maxLength={255}
                                autoComplete="off"
                                aria-invalid={errors.name ? true : undefined}
                                aria-describedby={describedBy('name')}
                            />
                            <InputError id="name-error" message={errors.name} />
                        </fieldset>

                        <fieldset className="fieldset py-0">
                            <label htmlFor="email" className={LABEL}>
                                Email
                            </label>
                            <label className="input input-sm w-full">
                                <Mail className="text-base-content/60 size-4" aria-hidden="true" />
                                <input
                                    id="email"
                                    type="email"
                                    className="grow"
                                    value={data.email}
                                    onChange={(e) => setData('email', e.target.value)}
                                    required
                                    maxLength={255}
                                    autoComplete="off"
                                    aria-invalid={errors.email ? true : undefined}
                                    aria-describedby={describedBy('email')}
                                />
                            </label>
                            <InputError id="email-error" message={errors.email} />
                        </fieldset>

                        <fieldset className="fieldset py-0">
                            <label htmlFor="role" className={LABEL}>
                                Role
                            </label>
                            <select
                                id="role"
                                className="select select-sm w-full"
                                value={data.role}
                                onChange={(e) => chooseRole(e.target.value)}
                                aria-required
                                aria-invalid={errors.role ? true : undefined}
                                aria-describedby={describedBy('role')}
                            >
                                <option value="" disabled>
                                    Choose a role
                                </option>
                                {options.roles.map((r) => (
                                    <option key={r.value} value={r.value}>
                                        {r.label}
                                    </option>
                                ))}
                            </select>
                            <InputError id="role-error" message={errors.role} />
                        </fieldset>

                        <fieldset className="fieldset py-0">
                            {needsOrganisation ? (
                                <>
                                    <label htmlFor="organisation" className={LABEL}>
                                        Organisation
                                    </label>
                                    <select
                                        id="organisation"
                                        className="select select-sm w-full"
                                        value={data.organisation}
                                        onChange={(e) => setData('organisation', e.target.value)}
                                        aria-required
                                        aria-invalid={errors.organisation ? true : undefined}
                                        aria-describedby={describedBy('organisation')}
                                    >
                                        <option value="" disabled>
                                            Choose the vendor organisation
                                        </option>
                                        {options.organisations.map((o) => (
                                            <option key={o.value} value={o.value}>
                                                {o.label}
                                            </option>
                                        ))}
                                    </select>
                                </>
                            ) : (
                                data.role !== '' && (
                                    <p className="label text-helper whitespace-normal lg:mt-7">Staff accounts belong to the Ministry of Health.</p>
                                )
                            )}
                            {/* A staff role: the server assigns the Ministry of Health. Errors still show. */}
                            <InputError id="organisation-error" message={errors.organisation} />
                        </fieldset>
                    </div>

                    <div className="card-actions border-base-300 bg-base-200 rounded-b-box justify-end border-t px-4 py-3 md:px-6">
                        <Link href={route('admin.users.index')} className="btn btn-sm capitalize">
                            Cancel
                        </Link>
                        <button type="submit" className="btn btn-primary btn-sm capitalize" disabled={processing} aria-busy={processing || undefined}>
                            {processing ? 'Creating…' : 'Create User'}
                        </button>
                    </div>
                </form>
            </PageContent>
        </AppLayout>
    );
}
