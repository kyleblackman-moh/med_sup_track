import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { type CaseListOptions, type CaseFilters as Filters } from '@/types/cases';
import { type OptionList } from '@/types/requests';
import { Search, X } from 'lucide-react';
import { useEffect, useState } from 'react';

const SEARCH_DEBOUNCE_MS = 300;
const SEARCH_MIN_CHARS = 3;
/** Radix Select items cannot have an empty value. */
const ALL = 'all';

type FilterKey = 'status' | 'type' | 'org' | 'ref' | 'awaiting';

interface Props {
    filters: Filters;
    options: CaseListOptions;
    showOrganisation: boolean;
    errors: Partial<Record<string, string>>;
    onChange: (changes: Partial<Pick<Filters, FilterKey>>) => void;
}

export function hasActiveFilters(filters: Filters): boolean {
    return filters.status !== '' || filters.type !== '' || filters.org !== '' || filters.ref !== '' || filters.awaiting !== '';
}

/** Filters apply as soon as they change; the URL holds their state. */
export function CaseFilters({ filters, options, showOrganisation, errors, onChange }: Props) {
    const [search, setSearch] = useState(filters.ref);

    // Follow the URL when it changes elsewhere (Clear filters, back button).
    useEffect(() => setSearch(filters.ref), [filters.ref]);

    useEffect(() => {
        const value = search.trim();
        if (value === filters.ref || (value.length > 0 && value.length < SEARCH_MIN_CHARS)) return;
        const timer = window.setTimeout(() => onChange({ ref: value }), SEARCH_DEBOUNCE_MS);
        return () => window.clearTimeout(timer);
    }, [search, filters.ref, onChange]);

    const select = (key: 'status' | 'type' | 'org', label: string, list: OptionList, all: string) => (
        <Select
            aria-label={label}
            value={filters[key] || ALL}
            onChange={(e) => onChange({ [key]: e.target.value === ALL ? '' : e.target.value })}
            className="bg-base-100 h-9 w-full sm:w-44"
        >
            <option value={ALL}>{all}</option>
            {list.map((o) => (
                <option key={o.value} value={o.value}>
                    {o.label}
                </option>
            ))}
        </Select>
    );

    const error = errors.ref ?? errors.status ?? errors.type ?? errors.org ?? errors.sort ?? errors.dir ?? errors.page;

    return (
        <div className="mb-4">
            <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
                <div className="relative w-full sm:w-64">
                    <Search
                        className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
                        aria-hidden="true"
                    />
                    <Input
                        type="search"
                        aria-label="Search by Reference"
                        placeholder="Search reference…"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        aria-invalid={errors.ref ? true : undefined}
                        aria-describedby={error ? 'case-filters-error' : undefined}
                        className="border-input bg-card h-9 pl-9"
                    />
                </div>
                {select('status', 'Status', options.statuses, 'All statuses')}
                {select('type', 'Type', options.types, 'All types')}
                {showOrganisation && select('org', 'Organisation', options.organisations, 'All organisations')}
                {filters.awaiting === 'funding' && (
                    <span className="bg-secondary text-secondary-foreground inline-flex h-9 items-center gap-1 self-start rounded-md pr-1 pl-3 text-sm sm:self-auto">
                        Awaiting CAB Funding
                        <button
                            type="button"
                            onClick={() => onChange({ awaiting: '' })}
                            className="hover:bg-background/60 focus-visible:ring-ring rounded-sm p-1 outline-none focus-visible:ring-2"
                            aria-label="Remove filter: awaiting CAB funding"
                        >
                            <X className="size-3.5" aria-hidden="true" />
                        </button>
                    </span>
                )}
                {hasActiveFilters(filters) && (
                    <Button
                        variant="ghost"
                        size="sm"
                        className="h-9 self-start sm:self-auto"
                        onClick={() => onChange({ status: '', type: '', org: '', ref: '', awaiting: '' })}
                    >
                        Clear Filters
                    </Button>
                )}
            </div>
            {error && (
                <p id="case-filters-error" role="alert" className="text-destructive mt-2 text-sm">
                    {error}
                </p>
            )}
        </div>
    );
}
