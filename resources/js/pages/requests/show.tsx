import { FlashMessages } from '@/components/requests/flash-messages';
import { RequisitionView } from '@/components/requests/requisition-view';
import { StatusBadge } from '@/components/requests/status-badge';
import { SubmitPanel } from '@/components/requests/submit-panel';
import { SupportingDocuments } from '@/components/requests/supporting-documents';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type DocumentGroup, type Readiness, type RequestDetail } from '@/types/requests';
import { Head, Link } from '@inertiajs/react';

interface Props {
    case: RequestDetail;
    documents: DocumentGroup[];
    readiness: Readiness | null;
    can: { submit: boolean };
}

export default function Show({ case: detail, documents, readiness, can }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Requests', href: route('requests.index') },
        { title: detail.reference_no ?? 'Draft', href: route('requests.show', detail.id) },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={detail.reference_no ?? 'Request'} />

            <div className="mx-auto w-full max-w-6xl space-y-8 p-4">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{detail.item_description ?? 'Request'}</h1>
                        <p className="text-muted-foreground text-sm">
                            {detail.procurement_type.name} · {detail.reference_no ?? 'Draft'}
                        </p>
                    </div>
                    <div className="flex items-center gap-3">
                        <StatusBadge label={detail.status_label} group={detail.status_group} />
                        {detail.can.edit && (
                            <Button asChild variant="outline" size="sm">
                                <Link href={route('requests.edit', detail.id)}>Edit draft</Link>
                            </Button>
                        )}
                        {detail.reference_no && (
                            <Button asChild variant="outline" size="sm">
                                <Link href={route('requests.receipt', detail.id)}>Receipt</Link>
                            </Button>
                        )}
                    </div>
                </header>

                <FlashMessages />

                {readiness && <SubmitPanel caseId={detail.id} readiness={readiness} canSubmit={can.submit} />}

                <RequisitionView content={detail} isPharm={detail.procurement_type.category === 'PHARM'} />

                <SupportingDocuments caseId={detail.id} groups={documents} readOnly />
            </div>
        </AppLayout>
    );
}
