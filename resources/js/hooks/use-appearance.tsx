/**
 * Light only (design.md section 1; NEXT part 4). Nothing reads a system
 * preference, a cookie or browser storage, and nothing adds the dark class.
 * initializeTheme() removes the class on start, so a value left in a browser by
 * an older build has no effect.
 */
export function initializeTheme() {
    document.documentElement.classList.remove('dark');
}
