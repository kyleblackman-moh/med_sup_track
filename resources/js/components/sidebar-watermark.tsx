/**
 * V2-4 (design.md section 6): the decorative watermark in the lower sidebar, a
 * medical cross and a heartbeat line at about 10% opacity. It sits in the flow
 * between the nav items and the user card, so it is never behind nav text.
 * Hidden from assistive technology, when the sidebar is collapsed to icons, on
 * short screens (it would push the nav into a scroll) and in print.
 */
export function SidebarWatermark() {
    return (
        <div
            aria-hidden="true"
            className="text-navy-foreground pointer-events-none hidden shrink-0 justify-center px-6 py-3 opacity-10 group-data-[collapsible=icon]:hidden print:hidden [@media(min-height:720px)]:flex"
        >
            <svg viewBox="0 0 160 110" className="h-auto w-full max-w-40" fill="none" focusable="false">
                <path d="M96 12h28v28h28v28h-28v28H96V68H68V40h28z" fill="currentColor" />
                <polyline
                    points="0,72 34,72 44,48 56,100 70,22 82,72 160,72"
                    stroke="currentColor"
                    strokeWidth="5"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                />
            </svg>
        </div>
    );
}
