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
    /** "23 Nov 2026": when it last arrived at this unit. */
    received_at: string;
    received_at_iso: string;
    seconds: number | null;
    days: string;
}

/** One stage visit of the case page's timeline (FileTracking::timeline). */
export interface TrackingStage {
    status: string;
    unit: string | null;
    unit_label: string | null;
    label: string;
    visit: number;
    holders: { role: string; label: string; name: string | null }[];
    entered_at: string;
    entered_at_iso: string;
    left_at: string | null;
    left_at_iso: string | null;
    open: boolean;
    duration_seconds: number;
    duration: string;
}

/** The case page's File Tracking tab. */
export interface CaseTracking {
    current: TrackingCurrent | null;
    units: TrackingUnit[];
    stages: TrackingStage[];
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
