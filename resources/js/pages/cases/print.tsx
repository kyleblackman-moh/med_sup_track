import { PrintForm, type PrintFormView } from '@/components/requests/print-form';

/** The staff printable request form (StaffCaseController::print). */
export default function CasePrint({ print }: { print: PrintFormView }) {
    const id = String(route().params.procurementCase ?? '');

    return <PrintForm print={print} backHref={route('cases.show', id)} backLabel="Back to Case" />;
}
