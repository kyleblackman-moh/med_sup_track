import { type OptionList } from '@/types/requests';
import { FilterX, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { type AdminUserFilters, type AdminUserOptions, NO_USER_FILTERS } from './types';

const SEARCH_DEBOUNCE_MS = 300;
const SEARCH_MIN_CHARS = 2;
/** The "all" option's value; the URL keeps an empty filter. */
const ALL = 'all';

type SelectKey = Exclude<keyof AdminUserFilters, 'q'>;

interface Props {
    filters: AdminUserFilters;
    options: AdminUserOptions;
    errors: Partial<Record<string, string>>;
    onChange: (changes: Partial<AdminUserFilters>) => void;
}

export function hasActiveUserFilters(filters: AdminUserFilters): boolean {
    return Object.values(filters).some((value) => value !== '');
}

/**
 * IT-ADMIN: search and the Type, Organisation, Role, Status (including
 * Pending activation and Locked) and MFA filters. Each applies as soon as it
 * changes; the URL holds their state and the server validates them (a bad
 * filter comes back as an error, shown under the bar).
 */
export function UserFilters({ filters, options, errors, onChange }: Props) {
    const [search, setSearch] = useState(filters.q);

    // Follow the URL when it changes elsewhere (Clear Filters, back button).
    useEffect(() => setSearch(filters.q), [filters.q]);

    useEffect(() => {
        const value = search.trim();
        if (value === filters.q || (value.length > 0 && value.length < SEARCH_MIN_CHARS)) return;
        const timer = window.setTimeout(() => onChange({ q: value }), SEARCH_DEBOUNCE_MS);
        return () => window.clearTimeout(timer);
    }, [search, filters.q, onChange]);

    const select = (key: SelectKey, label: string, list: OptionList, all: string) => (
        <div className="grid gap-1">
            <label htmlFor={`user-filter-${key}`} className="label text-helper capitalize">
                {label}
            </label>
            <select
                id={`user-filter-${key}`}
                aria-label={label}
                value={filters[key] || ALL}
                onChange={(e) => onChange({ [key]: e.target.value === ALL ? '' : e.target.value })}
                aria-invalid={errors[key] ? true : undefined}
                aria-describedby={errors[key] ? 'user-filters-error' : undefined}
                className="select select-sm w-full"
            >
                <option value={ALL}>{all}</option>
                {list.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </select>
        </div>
    );

    const error = errors.q ?? errors.type ?? errors.org ?? errors.role ?? errors.status ?? errors.mfa ?? errors.page;

    return (
        <section aria-label="Filter users" className="card bg-base-100 border-base-300 border">
            <div className="card-body gap-3 p-4">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    <div className="grid gap-1 sm:col-span-2 lg:col-span-3 xl:col-span-1">
                        <label htmlFor="user-filter-q" className="label text-helper capitalize">
                            Search
                        </label>
                        <label className="input input-sm w-full">
                            <Search className="text-base-content/60 size-4" aria-hidden="true" />
                            <input
                                id="user-filter-q"
                                type="search"
                                className="grow"
                                placeholder="Name or email"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                aria-invalid={errors.q ? true : undefined}
                                aria-describedby={error ? 'user-filters-error' : undefined}
                            />
                        </label>
                    </div>
                    {select('type', 'Type', options.types, 'All types')}
                    {select('org', 'Organisation', options.organisations, 'All organisations')}
                    {select('role', 'Role', options.roles, 'All roles')}
                    {select('status', 'Status', options.statuses, 'All statuses')}
                    {select('mfa', 'MFA', options.mfa, 'Enrolled or not')}
                </div>

                {(error || hasActiveUserFilters(filters)) && (
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        {error ? (
                            <p id="user-filters-error" role="alert" className="text-error text-sm">
                                {error}
                            </p>
                        ) : (
                            <span />
                        )}
                        {hasActiveUserFilters(filters) && (
                            <button type="button" className="btn btn-ghost btn-sm capitalize" onClick={() => onChange(NO_USER_FILTERS)}>
                                <FilterX className="size-4" aria-hidden="true" />
                                Clear Filters
                            </button>
                        )}
                    </div>
                )}
            </div>
        </section>
    );
}
