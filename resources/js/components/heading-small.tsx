/** A settings card's heading: the card title and one muted line under it. Used inside the settings pages (h2 under the page's h1). */
export default function HeadingSmall({ title, description }: { title: string; description?: string }) {
    return (
        <header className="space-y-0.5">
            <h2 className="text-base font-semibold capitalize">{title}</h2>
            {description && <p className="text-base-content/70 text-sm">{description}</p>}
        </header>
    );
}
