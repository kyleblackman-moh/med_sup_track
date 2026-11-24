import { Select } from '@/components/ui/select';
import { type CaseListOptions, type CaseFilters as Filters } from '@/types/cases';
import { type OptionList } from '@/types/requests';
import { FilterX, Search, X } from 'lucide-react';
import { useEffect, useState } from 'react';

const SEARCH_DEBOUNCE_MS = 300;
/** The server's q rule: at least 2 characters. */
const SEARCH_MIN_CHARS = 2;
/** Radix Select items cannot have an empty value. */
const ALL = 'all';

type FilterKey = 'status' | 'type' | 'org' | 'ref' | 'q' | 'awaiting';

interface Props {
    filters: Filters;
    options: CaseListOptions;
    showOrganisation: boolean;
    errors: Partial<Record<string, string>>;
    onChange: (changes: Partial<Pick<Filters, FilterKey>>) => void;
}

export function hasActiveFilters(filters: Filters): boolean {
    return filters.status !== '' || filters.type !== '' || filters.org !== '' || filters.ref !== '' || filters.q !== '' || filters.awaiting !== '';
}

/** Filters apply as soon as they change; the URL holds their state. One compact toolbar, Clear Filters last. */
export function CaseFilters({ filters, options, showOrganisation, errors, onChange }: Props) {
    const [search, setSearch] = useState(filters.q);

    // Follow the URL when it changes elsewhere (Clear filters, back button).
    useEffect(() => setSearch(filters.q), [filters.q]);

    useEffect(() => {
        const value = search.trim();
        if (value === filters.q || (value.length > 0 && value.length < SEARCH_MIN_CHARS)) return;
        const timer = window.setTimeout(() => onChange({ q: value }), SEARCH_DEBOUNCE_MS);
        return () => window.clearTimeout(timer);
    }, [search, filters.q, onChange]);

    const select = (key: 'status' | 'type' | 'org', label: string, list: OptionList, all: string) => (
        <Select
            aria-label={label}
            value={filters[key] || ALL}
            onChange={(e) => onChange({ [key]: e.target.value === ALL ? '' : e.target.value })}
            aria-invalid={errors[key] ? true : undefined}
            aria-describedby={errors[key] ? 'case-filters-error' : undefined}
            className={errors[key] ? 'select-sm select-error w-full capitalize sm:w-44' : 'select-sm w-full capitalize sm:w-44'}
        >
            <option value={ALL}>{all}</option>
            {list.map((o) => (
                <option key={o.value} value={o.value}>
                    {o.label}
                </option>
            ))}
        </Select>
    );

    const error = errors.q ?? errors.ref ?? errors.status ?? errors.type ?? errors.org ?? errors.sort ?? errors.dir ?? errors.page;

    return (
        <div className="card card-border bg-base-100 min-w-0">
            <div className="card-body flex-row flex-wrap items-center gap-2 p-2">
                <label className={errors.q ? 'input input-sm input-error w-full sm:w-64' : 'input input-sm w-full sm:w-64'}>
                    <Search className="size-4 opacity-60" aria-hidden="true" />
                    <input
                        type="search"
                        className="grow"
                        aria-label="Search Cases"
                        placeholder={showOrganisation ? 'Search reference, item or organisation…' : 'Search reference or item…'}
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        aria-invalid={errors.q ? true : undefined}
                        aria-describedby={error ? 'case-filters-error' : undefined}
                    />
                </label>
                {select('status', 'Status', options.statuses, 'All statuses')}
                {select('type', 'Type', options.types, 'All types')}
                {showOrganisation && select('org', 'Organisation', options.organisations, 'All organisations')}
                {filters.awaiting === 'funding' && (
                    <span className="badge badge-soft badge-lg gap-1 pr-1 capitalize">
                        Awaiting CAB Funding
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
                        onClick={() => onChange({ status: '', type: '', org: '', ref: '', q: '', awaiting: '' })}
                    >
                        <FilterX className="size-4" aria-hidden="true" />
                        Clear Filters
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
