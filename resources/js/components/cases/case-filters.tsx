import { Select } from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { type CaseListOptions, type CaseFilters as Filters } from '@/types/cases';
import { type OptionList } from '@/types/requests';
import { FilterX, Search, X } from 'lucide-react';
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

/** Filters apply as soon as they change; the URL holds their state. One compact toolbar, Clear filters last. */
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
            aria-invalid={errors[key] ? true : undefined}
            className={cn('select-sm w-full capitalize sm:w-44', errors[key] && 'select-error')}
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
        <div className="card bg-base-100 border-base-300 mb-3 border">
            <div className="card-body flex-row flex-wrap items-center gap-2 p-2">
                <label className={cn('input input-sm w-full sm:w-64', errors.ref && 'input-error')}>
                    <Search className="size-4 opacity-60" aria-hidden="true" />
                    <input
                        type="search"
                        className="grow"
                        aria-label="Search by reference"
                        placeholder="Search reference…"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        aria-invalid={errors.ref ? true : undefined}
                        aria-describedby={error ? 'case-filters-error' : undefined}
                    />
                </label>
                {select('status', 'Status', options.statuses, 'All statuses')}
                {select('type', 'Type', options.types, 'All types')}
                {showOrganisation && select('org', 'Organisation', options.organisations, 'All organisations')}
                {filters.awaiting === 'funding' && (
                    <span className="badge badge-soft badge-lg gap-1 pr-1 capitalize">
                        Awaiting CAB funding
                        <button
                            type="button"
                            onClick={() => onChange({ awaiting: '' })}
                            className="btn btn-ghost btn-xs btn-circle"
                            aria-label="Remove filter: awaiting CAB funding"
                        >
                            <X className="size-3.5" aria-hidden="true" />
                        </button>
                    </span>
                )}
                {hasActiveFilters(filters) && (
                    <button
                        type="button"
                        className="btn btn-ghost btn-sm capitalize sm:ml-auto"
                        onClick={() => onChange({ status: '', type: '', org: '', ref: '', awaiting: '' })}
                    >
                        <FilterX className="size-4" aria-hidden="true" />
                        Clear filters
                    </button>
                )}
                {error && (
                    <p id="case-filters-error" role="alert" className="text-error basis-full px-1 text-sm">
                        {error}
                    </p>
                )}
            </div>
        </div>
    );
}
