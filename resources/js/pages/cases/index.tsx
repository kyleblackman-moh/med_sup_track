import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type CaseFilters, type CaseListOptions, type CaseListPage } from '@/types/cases';
import { type OptionList } from '@/types/requests';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

interface Props {
    cases: CaseListPage;
    filters: CaseFilters;
    options: CaseListOptions;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Cases', href: '/cases' }];

const selectClass =
    'border-input bg-background focus-visible:ring-ring flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs outline-none focus-visible:ring-[3px]';

const SORT_OPTIONS: OptionList = [
    { value: 'submitted_at', label: 'Submitted date' },
    { value: 'reference_no', label: 'Reference' },
    { value: 'status', label: 'Status' },
];

function formatDate(iso: string | null): string {
    return iso
        ? new Date(iso).toLocaleDateString('en-TT', { timeZone: 'America/Port_of_Spain', day: 'numeric', month: 'short', year: 'numeric' })
        : '—';
}

/** Staff case list (slice 9a). Read-only; filters and paging are server-side. */
export default function CasesIndex({ cases, filters, options }: Props) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [form, setForm] = useState<CaseFilters>(filters);
    const set = (key: keyof CaseFilters, value: string) => setForm({ ...form, [key]: value });

    const apply = (e: FormEvent) => {
        e.preventDefault();
        const query = Object.fromEntries(Object.entries(form).filter(([, value]) => value !== ''));
        router.get(route('cases.index'), query, { preserveState: true, preserveScroll: true });
    };

    const filterSelect = (key: 'status' | 'type' | 'org', label: string, list: OptionList, all: string) => (
        <div className="grid gap-1.5">
            <Label htmlFor={`filter-${key}`}>{label}</Label>
            <select id={`filter-${key}`} className={selectClass} value={form[key]} onChange={(e) => set(key, e.target.value)}>
                <option value="">{all}</option>
                {list.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </select>
            <InputError message={errors[key]} />
        </div>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Cases" />

            <div className="mx-auto w-full max-w-7xl space-y-4 p-4">
                <h1 className="text-2xl font-semibold">Cases</h1>

                <form onSubmit={apply} className="bg-card grid gap-3 rounded-lg border p-4 sm:grid-cols-2 lg:grid-cols-6" aria-label="Filter cases">
                    <div className="grid gap-1.5">
                        <Label htmlFor="filter-ref">Reference contains</Label>
                        <Input id="filter-ref" value={form.ref} onChange={(e) => set('ref', e.target.value)} placeholder="At least 3 characters" />
                        <InputError message={errors.ref} />
                    </div>
                    {filterSelect('status', 'Status', options.statuses, 'All statuses')}
                    {filterSelect('type', 'Procurement type', options.types, 'All types')}
                    {filterSelect('org', 'Organisation', options.organisations, 'All organisations')}
                    <div className="grid gap-1.5">
                        <Label htmlFor="filter-sort">Sort by</Label>
                        <div className="flex gap-2">
                            <select id="filter-sort" className={selectClass} value={form.sort} onChange={(e) => set('sort', e.target.value)}>
                                {SORT_OPTIONS.map((o) => (
                                    <option key={o.value} value={o.value}>
                                        {o.label}
                                    </option>
                                ))}
                            </select>
                            <select aria-label="Sort direction" className={selectClass} value={form.dir} onChange={(e) => set('dir', e.target.value)}>
                                <option value="desc">Desc</option>
                                <option value="asc">Asc</option>
                            </select>
                        </div>
                        <InputError message={errors.sort ?? errors.dir ?? errors.page} />
                    </div>
                    <div className="flex items-end gap-2">
                        <Button type="submit">Apply</Button>
                        <Button asChild variant="ghost">
                            <Link href={route('cases.index')}>Clear</Link>
                        </Button>
                    </div>
                </form>

                <p className="text-muted-foreground text-sm" aria-live="polite">
                    {cases.total === 0 ? 'No cases to show.' : `Showing ${cases.from}–${cases.to} of ${cases.total}`}
                </p>

                {cases.data.length > 0 && (
                    <div className="bg-card overflow-x-auto rounded-lg border">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-muted/50">
                                <tr>
                                    {['Reference', 'Organisation', 'Type', 'Item', 'Status', 'Holder', 'Submitted', 'Days since submitted'].map(
                                        (h) => (
                                            <th key={h} scope="col" className="px-3 py-2 font-medium whitespace-nowrap">
                                                {h}
                                            </th>
                                        ),
                                    )}
                                </tr>
                            </thead>
                            <tbody>
                                {cases.data.map((c) => (
                                    <tr key={c.id} className="hover:bg-muted/30 border-t">
                                        <td className="px-3 py-2 font-medium whitespace-nowrap">
                                            <Link href={route('cases.show', c.id)} className="font-mono text-xs underline underline-offset-2">
                                                {c.reference_no ?? '—'}
                                            </Link>
                                        </td>
                                        <td className="px-3 py-2">{c.organisation ?? '—'}</td>
                                        <td className="px-3 py-2">{c.procurement_type ?? '—'}</td>
                                        <td className="text-muted-foreground px-3 py-2">{c.item_description ?? '—'}</td>
                                        <td className="px-3 py-2 whitespace-nowrap">
                                            <Badge variant="outline">{c.status_label}</Badge>
                                        </td>
                                        <td className="px-3 py-2 whitespace-nowrap">{c.holder_label ?? '—'}</td>
                                        <td className="px-3 py-2 whitespace-nowrap">{formatDate(c.submitted_at)}</td>
                                        <td className="px-3 py-2 tabular-nums">{c.days_since_submitted ?? '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {cases.last_page > 1 && (
                    <nav aria-label="Case list pages" className="flex items-center gap-2 text-sm">
                        <Button asChild={!!cases.prev_page_url} variant="outline" size="sm" disabled={!cases.prev_page_url}>
                            {cases.prev_page_url ? <Link href={cases.prev_page_url}>Previous</Link> : <span>Previous</span>}
                        </Button>
                        <span>
                            Page {cases.current_page} of {cases.last_page}
                        </span>
                        <Button asChild={!!cases.next_page_url} variant="outline" size="sm" disabled={!cases.next_page_url}>
                            {cases.next_page_url ? <Link href={cases.next_page_url}>Next</Link> : <span>Next</span>}
                        </Button>
                    </nav>
                )}
            </div>
        </AppLayout>
    );
}
