import { type StatusTone } from '@/types/cases';
import { type RequestSummary } from '@/types/requests';

/** One KPI tile (App\Support\Dashboard::kpi). `format` says how to show `value`. */
export interface DashboardKpi {
    key: string;
    label: string;
    value: number | null;
    format: 'count' | 'days';
    hint: string;
}

/** One stage row: staff statuses or vendor groups, in workflow order. */
export interface DashboardStage {
    key: string;
    label: string;
    tone: StatusTone;
    count: number;
    /** Staff only: the user has queue steps at this stage. */
    mine: boolean;
}

export interface DashboardStages {
    /** all = every case; assigned = cases the user holds or was assigned; own = the vendor's organisation. */
    scope: 'all' | 'assigned' | 'own';
    items: DashboardStage[];
}

export interface GateRow {
    case_id: string;
    reference_no: string | null;
    technical: boolean;
    funding: boolean;
    hardcopy: boolean;
    waiting_on: string[];
}

export interface ActivityRow {
    id: number;
    case_id: string;
    reference_no: string | null;
    from_label: string | null;
    to_label: string;
    to_tone: StatusTone;
    actor: string | null;
    at: string | null;
}

export interface StaffWidgets {
    kind: 'staff';
    kpis: DashboardKpi[];
    stages: DashboardStages;
    gate: { total: number; items: GateRow[] } | null;
    activity: ActivityRow[];
}

export interface VendorWidgets {
    kind: 'vendor';
    kpis: DashboardKpi[];
    stages: DashboardStages;
    drafts: { total: number; items: RequestSummary[] };
    requests: { total: number; items: RequestSummary[] };
}

export type DashboardWidgets = StaffWidgets | VendorWidgets;
