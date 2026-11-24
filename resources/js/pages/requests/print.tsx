import { PrintForm, type PrintFormView } from '@/components/requests/print-form';

/** The vendor's printable request form (RequestController::print). */
export default function RequestPrint({ print }: { print: PrintFormView }) {
    const id = String(route().params.procurementCase ?? '');

    return <PrintForm print={print} backHref={route('requests.show', id)} backLabel="Back to Request" />;
}
