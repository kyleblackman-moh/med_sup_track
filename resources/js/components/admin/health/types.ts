/** IT-ADMIN Part 3: the props SystemHealthController sends to admin/health. Every value is server-made. */

export type HealthTone = 'neutral' | 'success' | 'warning' | 'error';

/** One count, worded by the server, linking to the list behind it (null when there is no list). */
export interface HealthCard {
    key: string;
    label: string;
    /** Null when the count could not be read (for example the queue size). */
    value: number | null;
    href: string | null;
    tone: HealthTone;
    /** Security cards only: the daily average of the 7 days before the last 24 hours. */
    comparison?: number;
    comparison_label?: string;
    trend?: 'up' | 'down' | 'flat';
}

export interface ClamavCheck {
    status: 'reachable' | 'unreachable' | 'not_used';
    label: string;
    host: string | null;
    tone: HealthTone;
}

export interface DiskCheck {
    free_gb: number | null;
    total_gb: number | null;
    warn_below_gb: number;
    warning: boolean;
    tone: HealthTone;
}

export interface AuditSeal {
    configured: boolean;
    label: string;
    tone: HealthTone;
}

/** One Port of Spain day, oldest first, today last. */
export interface FailedLoginDay {
    date: string;
    label: string;
    count: number;
}

/** FailedJobView::row: the job and exception class only; failed_at is already Port of Spain. */
export interface FailedJob {
    uuid: string;
    job: string;
    connection: string;
    queue: string;
    exception: string;
    failed_at: string;
}
