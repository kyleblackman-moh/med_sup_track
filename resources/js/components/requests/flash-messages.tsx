import { type Flash } from '@/types/requests';
import { usePage } from '@inertiajs/react';
import { CircleAlert, CircleCheck, Info } from 'lucide-react';

/**
 * Success / info / error messages flashed by the server, announced politely.
 * Blueprint alerts (soft, an icon AND text, never colour alone).
 */
export function FlashMessages() {
    const { flash } = usePage<{ flash: Flash }>().props;

    return (
        <div aria-live="polite" className="space-y-2 empty:hidden">
            {flash?.success && (
                <div className="alert alert-success alert-soft text-sm">
                    <CircleCheck className="size-4 shrink-0" aria-hidden="true" />
                    <span className="min-w-0 break-words">{flash.success}</span>
                </div>
            )}
            {flash?.info && (
                <div className="alert bg-base-100 border-base-300 text-sm">
                    <Info className="text-primary size-4 shrink-0" aria-hidden="true" />
                    <span className="min-w-0 break-words">{flash.info}</span>
                </div>
            )}
            {flash?.error && (
                <div className="alert alert-error alert-soft text-sm">
                    <CircleAlert className="size-4 shrink-0" aria-hidden="true" />
                    <span className="min-w-0 break-words">{flash.error}</span>
                </div>
            )}
        </div>
    );
}
