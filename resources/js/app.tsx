// Self-hosted fonts (design.md section 4): Roboto and JetBrains Mono are both
// declared latin-only in app.css and bundled by Vite. No font CDN.
import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { route as routeFn } from 'ziggy-js';
import { initializeTheme } from './hooks/use-appearance';

declare global {
    const route: typeof routeFn;
}

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    progress: {
        // A token, not a hex value (design.md section 2): Inertia puts it into CSS as is.
        color: 'var(--primary)',
    },
});

// Light only: makes sure the .dark class is never present.
initializeTheme();
