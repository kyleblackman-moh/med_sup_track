import { ActionFacts, ActionPanel, actionTextareaClass } from '@/components/cases/action-panel';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { RadioGroup } from '@/components/ui/radio-group';
import { Select } from '@/components/ui/select';
import { type PsForm, type PsOutcome } from '@/types/cases';
import { useForm } from '@inertiajs/react';
import { Landmark, Stamp } from 'lucide-react';
import { FormEvent, useState } from 'react';

const TZ = 'America/Port_of_Spain';

/** Field label inside an action panel (daisyUI fieldset label). */
const LABEL = 'label text-base-content text-sm font-medium capitalize';

function formatDateTime(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString('en-TT', { timeZone: TZ, dateStyle: 'medium', timeStyle: 'short' }) : '';
}

/**
 * The PS decision (slice 10d), for a user the server allows at With PS. Grant,
 * or return the file with a query type and a reason. A summary is confirmed
 * before it is recorded, because the first decision is final.
 */
export function PsDecisionForm({ caseId, form: def }: { caseId: string; form: PsForm }) {
    const form = useForm<{ decision: string; return_kind: string; reason: string }>({ decision: '', return_kind: '', reason: '' });
    const errors = form.errors as Record<string, string>;
    const [confirming, setConfirming] = useState(false);
    const chosen = def.decisions.find((d) => d.value === form.data.decision);
    const isReturn = chosen?.needs_reason === true;
    const kind = def.kinds.find((k) => k.value === form.data.return_kind);
    const reason = form.data.reason.trim();

    const choose = (value: string) => form.setData((data) => ({ ...data, decision: value, return_kind: value === 'grant' ? '' : data.return_kind }));

    const review = (e: FormEvent) => {
        e.preventDefault();
        setConfirming(true);
    };

    const record = () =>
        form.post(route('cases.ps.decide', caseId), {
            preserveScroll: true,
            preserveState: 'errors',
            onFinish: () => setConfirming(false),
        });

    return (
        <ActionPanel
            title="PS Decision"
            icon={Landmark}
            description="Grant non-objection, or return the file with a query type and a reason."
            onSubmit={review}
            actions={
                <Button
                    type="submit"
                    size="sm"
                    className="active:scale-[0.97]"
                    disabled={form.processing || form.data.decision === '' || (isReturn && form.data.return_kind === '')}
                >
                    Record Decision
                </Button>
            }
        >
            <RadioGroup
                name="ps-decision"
                legend="Decision"
                options={def.decisions}
                value={form.data.decision}
                onChange={choose}
                optionId={(d) => `ps-${d.value}`}
                describedBy="ps-decision-error"
            >
                <InputError id="ps-decision-error" message={errors.decision ?? errors.ps_decision} />
            </RadioGroup>

            {isReturn && (
                <fieldset className="fieldset py-0">
                    <label className={LABEL} htmlFor="ps-return-kind">
                        Query Type (required)
                    </label>
                    <Select
                        id="ps-return-kind"
                        aria-required="true"
                        className="select-sm max-w-sm"
                        value={form.data.return_kind}
                        onChange={(e) => form.setData('return_kind', e.target.value)}
                        aria-invalid={errors.return_kind ? true : undefined}
                        aria-describedby="ps-return-kind-error"
                    >
                        <option value="" disabled>
                            Choose a query type
                        </option>
                        {def.kinds.map((k) => (
                            <option key={k.value} value={k.value}>
                                {k.label}
                            </option>
                        ))}
                    </Select>
                    <InputError id="ps-return-kind-error" message={errors.return_kind} />
                </fieldset>
            )}

            <fieldset className="fieldset py-0">
                <label className={LABEL} htmlFor="ps-reason">
                    {isReturn ? 'Reason for the Query (required)' : 'Note'}
                </label>
                <textarea
                    id="ps-reason"
                    aria-required={isReturn ? true : undefined}
                    rows={3}
                    maxLength={500}
                    className={actionTextareaClass}
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    aria-invalid={errors.reason ? true : undefined}
                    aria-describedby="ps-reason-hint ps-reason-error"
                />
                <p id="ps-reason-hint" className="label whitespace-normal">
                    {isReturn ? '10 to 500 characters. Staff can see it; the vendor cannot.' : 'Up to 500 characters. Staff only.'}
                </p>
                <InputError id="ps-reason-error" message={errors.reason} />
            </fieldset>

            <Dialog open={confirming} onOpenChange={(open) => !form.processing && setConfirming(open)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Record this PS decision?</DialogTitle>
                        <DialogDescription>Check the summary. Once recorded, the decision cannot be changed here.</DialogDescription>
                    </DialogHeader>
                    <ActionFacts
                        items={[
                            ['Decision', chosen?.label ?? ''],
                            ...(isReturn ? ([['Query Type', kind?.label ?? 'Not chosen']] as [string, string][]) : []),
                            [isReturn ? 'Reason' : 'Note', reason === '' ? 'None' : reason],
                            ['Next', chosen?.next ?? ''],
                        ]}
                    />
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setConfirming(false)} disabled={form.processing}>
                            Go Back
                        </Button>
                        <Button type="button" onClick={record} disabled={form.processing}>
                            {form.processing ? 'Recording…' : 'Record Decision'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </ActionPanel>
    );
}

/** The recorded PS decision, in place of the form (slice 10d). Staff only. */
export function PsOutcomeStatement({ outcome }: { outcome: PsOutcome }) {
    return (
        <ActionPanel title="PS Decision" icon={Stamp} description="The decision is recorded and cannot be changed here.">
            <p className="text-sm">
                {outcome.outcome}
                {outcome.by && ` by ${outcome.by}`}
                {outcome.at && `, ${formatDateTime(outcome.at)}`}.
            </p>
        </ActionPanel>
    );
}
