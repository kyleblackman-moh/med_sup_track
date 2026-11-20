import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { type AdminUserFilters, type AdminUserOptions } from '@/types/admin';
import { type OptionList } from '@/types/requests';
import { Search } from 'lucide-react';
import { useEffect, useState } from 'react';

const SEARCH_DEBOUNCE_MS = 300;
const SEARCH_MIN_CHARS = 2;
/** The "all" option's value; the URL keeps an empty filter. */
const ALL = 'all';

interface Props {
    filters: AdminUserFilters;
    options: AdminUserOptions;
    errors: Partial<Record<string, string>>;
    onChange: (changes: Partial<AdminUserFilters>) => void;
}

export function hasActiveUserFilters(filters: AdminUserFilters): boolean {
    return filters.q !== '' || filters.org !== '' || filters.role !== '' || filters.status !== '';
}

/** ADMIN-1: search and filters apply as soon as they change; the URL holds their state (server-side). */
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

    const select = (key: 'org' | 'role' | 'status', label: string, list: OptionList, all: string) => (
        <Select
            value={filters[key] || ALL}
            onChange={(e) => onChange({ [key]: e.target.value === ALL ? '' : e.target.value })}
            aria-label={label}
            className="select-sm w-full sm:w-48"
        >
            <option value={ALL}>{all}</option>
            {list.map((o) => (
                <option key={o.value} value={o.value}>
                    {o.label}
                </option>
            ))}
        </Select>
    );

    const error = errors.q ?? errors.org ?? errors.role ?? errors.status ?? errors.page;

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
                        aria-label="Search by Name or Email"
                        placeholder="Search name or email…"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        aria-invalid={errors.q ? true : undefined}
                        aria-describedby={error ? 'user-filters-error' : undefined}
                        className="border-input bg-card h-9 pl-9"
                    />
                </div>
                {select('org', 'Organisation', options.organisations, 'All organisations')}
                {select('role', 'Role', options.roles, 'All roles')}
                {select('status', 'Status', options.statuses, 'All statuses')}
                {hasActiveUserFilters(filters) && (
                    <Button
                        variant="ghost"
                        size="sm"
                        className="h-9 self-start sm:self-auto"
                        onClick={() => onChange({ q: '', org: '', role: '', status: '' })}
                    >
                        Clear Filters
                    </Button>
                )}
            </div>
            {error && (
                <p id="user-filters-error" role="alert" className="text-destructive mt-2 text-sm">
                    {error}
                </p>
            )}
        </div>
    );
}
