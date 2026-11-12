import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface ProcurementTypeOption {
    id: number;
    code: string;
    name: string;
    category: 'PHARM' | 'NON_PHARM';
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Requests', href: '/requests' },
    { title: 'New request', href: '/requests/create' },
];

const CATEGORY_LABELS = { PHARM: 'Pharmaceutical', NON_PHARM: 'Non-pharmaceutical' } as const;

export default function Create({ types }: { types: ProcurementTypeOption[] }) {
    const { data, setData, post, processing, errors } = useForm<{ procurement_type_id: string }>({ procurement_type_id: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('requests.store'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New request" />

            <form onSubmit={submit} className="mx-auto w-full max-w-3xl space-y-6 p-4">
                <h1 className="text-2xl font-semibold">New request</h1>

                <fieldset className="space-y-3" aria-describedby={errors.procurement_type_id ? 'type-error' : undefined}>
                    <legend className="mb-2 text-base font-medium">What kind of purchase is this?</legend>

                    {types.map((type) => (
                        <label
                            key={type.id}
                            className={cn(
                                'flex cursor-pointer items-start gap-3 rounded-lg border p-4',
                                data.procurement_type_id === String(type.id) && 'border-primary ring-primary ring-1',
                            )}
                        >
                            <input
                                type="radio"
                                name="procurement_type_id"
                                value={type.id}
                                checked={data.procurement_type_id === String(type.id)}
                                onChange={() => setData('procurement_type_id', String(type.id))}
                                className="mt-1"
                            />
                            <span>
                                <span className="block font-medium">{type.name}</span>
                                <span className="text-muted-foreground block text-sm">{CATEGORY_LABELS[type.category]}</span>
                            </span>
                        </label>
                    ))}

                    <InputError id="type-error" message={errors.procurement_type_id} />
                </fieldset>

                <Button type="submit" disabled={processing || data.procurement_type_id === ''}>
                    Start draft
                </Button>
            </form>
        </AppLayout>
    );
}
