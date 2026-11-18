import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * "New request" (V2-1b posts straight to requests.store, which creates the
 * draft and opens it). UI-4: disabled while the POST runs, so a double click
 * cannot create two drafts. The server authorises the POST; whether to show
 * the button is the page's `can` flag.
 */
export function NewRequestButton() {
    const [processing, setProcessing] = useState(false);

    return (
        <Button
            type="button"
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
            New request
        </Button>
    );
}
