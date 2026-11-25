import { PageContent } from '@/components/page-header';
import { IssuesPanel, SubmitDialog, type SummaryItem } from '@/components/requests/ad-hoc/review';
import { CaseHero } from '@/components/requests/case-hero';
import { FlashMessages } from '@/components/requests/flash-messages';
import { RequestSummary } from '@/components/requests/request-summary';
import { StickyActionBar } from '@/components/ui/sticky-action-bar';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type AdHocDetail } from '@/types/ad-hoc';
import { type RequestSummaryView } from '@/types/summary';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, FileDown, Send } from 'lucide-react';
import { useState } from 'react';

/** OVERNIGHT-3 (A1): one line of what still blocks a submit, built by the server (RequestReview::blockers). */
interface Blocker {
    key: string;
    section_label: string;
    message: string;
    /** The form, at the line's section (/requests/{case}/edit#section-key). */
    href: string;
}

interface Props {
    case: AdHocDetail;
    summary: RequestSummaryView | null;
    blockers: Blocker[];
    issue_heading: string | null;
    description: string;
    not_ready_reason: string | null;
    hardcopy_notice: string;
    can: { submit: boolean; requests: boolean };
}

/**
 * OVERNIGHT-3 (A1): Review as its own page (the vendor's own DRAFT only; the
 * server answers 404 or redirects otherwise). The case hero holds the page's
 * one h1. The read-only RequestSummary of what is saved, the server's list of
 * what is missing (the form's error summary, linking back to the form), and a
 * bar with Back to Edit and Submit Request. Submit uses the existing submit
 * route and confirmation, and lands where it always has (the request page).
 */
export default function Review({ case: detail, summary, blockers, issue_heading, description, not_ready_reason, hardcopy_notice, can }: Props) {
    const [confirming, setConfirming] = useState(false);
    const [submitting, setSubmitting] = useState(false);

    const items: SummaryItem[] = blockers.map((blocker) => ({
        key: blocker.key,
        sectionLabel: blocker.section_label,
        message: blocker.message,
        target: '',
        href: blocker.href,
    }));

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Requests', href: route('requests.index') },
        { title: detail.reference_no ?? 'New Request', href: route('requests.edit', detail.id) },
        { title: 'Review', href: route('requests.review', detail.id) },
    ];

    const submit = () =>
        router.post(
            route('requests.submit', detail.id),
            {},
            {
                onStart: () => setSubmitting(true),
                // Refused (something changed meanwhile): the server sends this page back with what is missing.
                onError: () => setConfirming(false),
                onFinish: () => setSubmitting(false),
            },
        );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Review Your Request" />

            {/* Full width beside the drawer: px-4 py-4, lg:px-6 (PageContent's md:p-6 reset to p-4 first). */}
            <PageContent className="space-y-4 px-4 py-4 md:p-4 lg:px-6">
                <CaseHero hero={detail.hero} />

                <FlashMessages />

                <section aria-labelledby="review-heading" className="space-y-1">
                    <h2 id="review-heading" className="text-xl font-bold capitalize">
                        Review Your Request
                    </h2>
                    {can.submit && <p className="text-base-content/70 text-sm">{description}</p>}
                </section>

                <IssuesPanel heading={issue_heading} items={items} announce={false} />

                {summary && <RequestSummary summary={summary} idPrefix="review" />}

                <StickyActionBar
                    left={
                        <Link href={route('requests.edit', detail.id)} className="btn btn-outline capitalize max-md:h-11">
                            <ArrowLeft className="size-4" aria-hidden="true" />
                            Back to Edit
                        </Link>
                    }
                    right={
                        <>
                            {/* Backlog v0.4: the form as a PDF download (a file, so a plain link, not an Inertia visit). */}
                            {summary && (
                                <a href={route('requests.letter', detail.id)} className="btn btn-outline capitalize max-md:h-11" download>
                                    <FileDown className="size-4" aria-hidden="true" />
                                    Export PDF
                                </a>
                            )}
                            {!can.submit && not_ready_reason && (
                                <p id="submit-disabled-reason" className="text-base-content/70 text-sm">
                                    {not_ready_reason}
                                </p>
                            )}
                            <button
                                type="button"
                                className="btn btn-primary capitalize max-md:h-11"
                                disabled={!can.submit || submitting}
                                aria-busy={submitting || undefined}
                                aria-describedby={!can.submit && not_ready_reason ? 'submit-disabled-reason' : undefined}
                                onClick={() => setConfirming(true)}
                            >
                                {submitting ? (
                                    <span className="loading loading-spinner loading-xs" aria-hidden="true" />
                                ) : (
                                    <Send className="size-4" aria-hidden="true" />
                                )}
                                Submit Request
                            </button>
                        </>
                    }
                />
            </PageContent>

            <SubmitDialog
                open={confirming}
                onOpenChange={setConfirming}
                hardcopyNotice={hardcopy_notice}
                submitting={submitting}
                onConfirm={submit}
            />
        </AppLayout>
    );
}
