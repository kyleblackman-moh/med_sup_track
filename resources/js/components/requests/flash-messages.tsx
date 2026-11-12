import { type Flash } from '@/types/requests';
import { usePage } from '@inertiajs/react';

/** Success / error messages flashed by the server, announced politely. */
export function FlashMessages() {
    const { flash } = usePage<{ flash: Flash }>().props;

    return (
        <div aria-live="polite" className="empty:hidden">
            {flash?.success && (
                <p className="rounded-md border border-green-300 bg-green-50 px-4 py-2 text-sm text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-200">
                    {flash.success}
                </p>
            )}
            {flash?.error && (
                <p className="rounded-md border border-red-300 bg-red-50 px-4 py-2 text-sm text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200">
                    {flash.error}
                </p>
            )}
        </div>
    );
}
