/**
 * NOTIF-2/3: one bell row, exactly as Bell::row sends it. A pointer only: the
 * page it links to authorises itself when opened.
 */
export interface NotificationRow {
    id: string;
    /** 'action' when the user has something to do; 'info' otherwise. */
    kind: 'action' | 'info' | null;
    event: string | null;
    title: string | null;
    /** The case reference (NR-...), or null when the case had none yet. */
    reference: string | null;
    url: string | null;
    read: boolean;
    /** UTC ISO 8601. */
    created_at: string | null;
}

export type NotificationFilter = 'all' | 'action' | 'unread';

export interface NotificationPage {
    data: NotificationRow[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
}
