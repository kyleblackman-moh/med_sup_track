import { type StatusTone } from '@/types/cases';

/** One unit's total time with a file (FileTracking::units). */
export interface TrackingUnit {
    key: string;
    label: string;
    seconds: number;
    /** "Under 1 day", "3 days", or the dash when never reached. */
    days: string;
    visited: boolean;
    current: boolean;
}

/** Where the file is now and since when (FileTracking::units); null once the case is closed. */
export interface TrackingCurrent {
    key: string;
    label: string;
    /** "23 Nov 2026": when it last arrived at this unit. Null when the viewer may not see this unit's times (FileTracking::forViewer). */
    received_at: string | null;
    received_at_iso: string | null;
    seconds: number | null;
    days: string | null;
}

/** One stage visit of the case page's timeline (FileTracking::timeline). */
export interface TrackingStage {
    status: string;
    unit: string | null;
    unit_label: string | null;
    label: string;
    visit: number;
    holders: { role: string; label: string; name: string | null }[];
    /** Null, with the duration, when the viewer may not see this stage's times (FileTracking::forViewer). */
    entered_at: string | null;
    entered_at_iso: string | null;
    left_at: string | null;
    left_at_iso: string | null;
    open: boolean;
    duration_seconds: number;
    duration: string | null;
    /** Kyle, 2026-11-26: the viewer's own part (always true for the CMO's and PS Offices). */
    mine?: boolean;
}

/** Kyle, 2026-11-26: one step of the file's path (FileTracking::path), in its lane and column. */
export interface TrackingPathStep {
    lane: string;
    /** start: the vendor sent it; stage: a stage visit; beside: CAB with the first review; query: a branch to the vendor; end: closed. */
    kind: 'start' | 'stage' | 'beside' | 'query' | 'end';
    label: string;
    /** Index into stages: the stage this step belongs to. */
    stage: number;
    /** Null when the viewer may not see this step's times. */
    entered_at: string | null;
    left_at: string | null;
    duration: string | null;
    open: boolean;
    column: number;
    number: number;
    /** Kyle, 2026-11-26: the viewer's own part; only these show their times. */
    mine?: boolean;
}

/** Kyle, 2026-11-26: "Where Is The File": the lanes top to bottom and the steps left to right. */
export interface TrackingPath {
    lanes: { key: string; label: string }[];
    steps: TrackingPathStep[];
    columns: number;
}

/** The case page's Where Is The File tab. */
export interface CaseTracking {
    current: TrackingCurrent | null;
    units: TrackingUnit[];
    stages: TrackingStage[];
    path: TrackingPath;
    /** Kyle, 2026-11-26: true when the viewer sees the times of their own part only. */
    restricted?: boolean;
}

/** One dashboard board row (FileTracking::board). */
export interface TrackingRow {
    id: string;
    reference: string | null;
    item: string | null;
    din: string | null;
    href: string;
    stage: string;
    status_tone: StatusTone;
    holder_label: string | null;
    current: TrackingCurrent | null;
    units: TrackingUnit[];
}

export interface TrackingBoard {
    rows: TrackingRow[];
    total: number;
    units: Record<string, string>;
    more_href: string | null;
}

/** Backlog, 2026-11-25: one of the gate's three ticks (StaffCaseDetail::gateTicks), for a case in review. */
export interface GateTick {
    key: string;
    label: string;
    done: boolean;
    state: string;
}

/** Backlog, 2026-11-25: one row of the File Tracking page (FileTracking::rows plus the gate ticks). */
export interface FileTrackingRow {
    id: string;
    reference: string | null;
    item: string | null;
    stage: string;
    status_tone: StatusTone;
    holders: { role: string; label: string; name: string | null }[];
    holder_label: string | null;
    at_stage_seconds: number | null;
    at_stage: string;
    since_submission_seconds: number | null;
    since_submission: string;
    times_returned: number;
    /** Null outside In Review. */
    gate: GateTick[] | null;
}

/** The opened row's stage timeline (the optional `timeline` prop). */
export interface FileTrackingTimeline {
    id: string;
    stages: TrackingStage[];
}
