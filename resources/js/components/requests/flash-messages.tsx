import { Notice } from '@/components/ui/notice';
import { type Flash } from '@/types/requests';
import { usePage } from '@inertiajs/react';

/** Success / info / error messages flashed by the server, announced politely. OVERNIGHT-3 (B1): the shared Notice tones. */
export function FlashMessages() {
    const { flash } = usePage<{ flash: Flash }>().props;

    return (
        <div aria-live="polite" className="space-y-2 empty:hidden">
            {flash?.success && <Notice tone="success">{flash.success}</Notice>}
            {flash?.info && <Notice tone="neutral">{flash.info}</Notice>}
            {flash?.error && <Notice tone="danger">{flash.error}</Notice>}
        </div>
    );
}
