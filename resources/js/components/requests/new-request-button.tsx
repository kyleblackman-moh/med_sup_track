import { router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';

/**
 * "New Request" (V2-1b posts straight to requests.store, which creates the
 * draft and opens it). UI-4: disabled while the POST runs, so a double click
 * cannot create two drafts. The server authorises the POST; whether to show
 * the button is the page's `can` flag. Blueprint: the page's one primary button.
 */
export function NewRequestButton() {
    const [processing, setProcessing] = useState(false);

    return (
        <button
            type="button"
            className="btn btn-primary btn-sm capitalize max-md:h-11"
            disabled={processing}
            aria-busy={processing}
            onClick={() =>
                router.post(
                    route('requests.store'),
                    {},
                    {
                        onStart: () => setProcessing(true),
                        onFinish: () => setProcessing(false),
                    },
                )
            }
        >
            {processing ? <span className="loading loading-spinner loading-xs" aria-hidden="true" /> : <Plus className="size-4" aria-hidden="true" />}
            New Request
        </button>
    );
}
