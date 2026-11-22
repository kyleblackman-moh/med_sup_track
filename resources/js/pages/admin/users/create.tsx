import InputError from '@/components/input-error';
import { PageContent, PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type RoleOption } from '@/types/admin';
import { type OptionList } from '@/types/requests';
import { Head, Link, useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

interface Props {
    options: { roles: RoleOption[]; organisations: OptionList };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Users', href: '/admin/users' },
    { title: 'New User', href: '/admin/users/create' },
];

/**
 * ADMIN-1 Step 2: create an account. The Organisation field shows only for a
 * role the server marks `vendor`; staff roles join the Ministry of Health on
 * the server. No password is entered or shown: the person is emailed a link
 * to set their own. The server validates the role and organisation pairing.
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
                <PageHeader title="New User" subtitle="They will get an email with a link to set their own password." />

                <form onSubmit={submit} noValidate className="bg-card max-w-2xl rounded-xl border shadow-xs">
                    <div className="grid gap-5 p-5 md:p-6">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                required
                                maxLength={255}
                                autoComplete="off"
                                aria-invalid={errors.name ? true : undefined}
                                aria-describedby={describedBy('name')}
                            />
                            <InputError id="name-error" message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="email">Email</Label>
                            <Input
                                id="email"
                                type="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                required
                                maxLength={255}
                                autoComplete="off"
                                aria-invalid={errors.email ? true : undefined}
                                aria-describedby={describedBy('email')}
                            />
                            <InputError id="email-error" message={errors.email} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="role">Role</Label>
                            <Select
                                value={data.role}
                                onChange={(e) => chooseRole(e.target.value)}
                                id="role"
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
                            </Select>
                            <InputError id="role-error" message={errors.role} />
                        </div>

                        {needsOrganisation ? (
                            <div className="grid gap-2">
                                <Label htmlFor="organisation">Organisation</Label>
                                <Select
                                    value={data.organisation}
                                    onChange={(e) => setData('organisation', e.target.value)}
                                    id="organisation"
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
                                </Select>
                                <InputError id="organisation-error" message={errors.organisation} />
                            </div>
                        ) : (
                            // A staff role: the server assigns the Ministry of Health. Errors still show.
                            <>
                                {data.role !== '' && (
                                    <p className="text-muted-foreground text-[13px]">Staff accounts belong to the Ministry of Health.</p>
                                )}
                                <InputError id="organisation-error" message={errors.organisation} />
                            </>
                        )}
                    </div>

                    {/* Form actions in a footer row at the bottom of the form (CLAUDE.md), never in the header. */}
                    <div className="bg-subtle flex flex-wrap justify-end gap-2 rounded-b-xl border-t px-5 py-4 md:px-6">
                        <Button asChild variant="outline" className="h-11 md:h-10">
                            <Link href={route('admin.users.index')}>Cancel</Link>
                        </Button>
                        <Button type="submit" disabled={processing} aria-busy={processing} className="h-11 md:h-10">
                            {processing ? 'Creating…' : 'Create User'}
                        </Button>
                    </div>
                </form>
            </PageContent>
        </AppLayout>
    );
}
