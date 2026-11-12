import { Badge } from '@/components/ui/badge';

const VARIANTS: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    DRAFT: 'outline',
    SUBMITTED: 'secondary',
    UNDER_REVIEW: 'secondary',
    LETTER_ISSUED: 'default',
    CLOSED: 'outline',
    WITHDRAWN: 'outline',
    NOT_APPROVED: 'destructive',
};

/** Shows the server's vendor-facing label; the group only picks a colour. */
export function StatusBadge({ label, group }: { label: string; group: string }) {
    return <Badge variant={VARIANTS[group] ?? 'outline'}>{label}</Badge>;
}
