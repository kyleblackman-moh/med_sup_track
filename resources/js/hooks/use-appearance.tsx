import { useState } from 'react';

export type Appearance = 'light' | 'dark' | 'system';

/**
 * Light only for launch (design.md section 1). The .dark variables stay in
 * app.css, but nothing applies the class: no system-preference detection, no
 * saved preference. Any appearance choice resolves to light.
 */
const forceLight = () => {
    document.documentElement.classList.remove('dark');
};

export function initializeTheme() {
    forceLight();
}

export function useAppearance() {
    const [appearance, setAppearance] = useState<Appearance>('light');

    const updateAppearance = (mode: Appearance) => {
        setAppearance(mode);
        forceLight();
    };

    return { appearance, updateAppearance };
}
