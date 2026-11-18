<?php

namespace App\Support;

use App\Enums\CaseStatus as S;
use Illuminate\Database\Eloquent\Builder;

/**
 * UI-4: the vendor Requests page's four count cards. Every request is in at
 * most one group (WITHDRAWN and REJECTED_FINAL are in none):
 *
 * - Drafts: DRAFT.
 * - Hardcopy due: submitted and still open, the hardcopy not received yet
 *   (hardcopy_received_on IS NULL). No claim about any deadline.
 * - In progress: submitted and still open, the hardcopy received. This
 *   includes NON_OBJECTION_GRANTED: the vendor sees "Under review" until the
 *   letter is issued.
 * - Granted: LETTER_ISSUED and CLOSED (the vendor label "Non-objection granted").
 *
 * The mapping lives here only: the cards, their filter and their counts.
 */
final class RequestGroups
{
    public const DRAFTS = 'drafts';

    public const IN_PROGRESS = 'in_progress';

    public const HARDCOPY_DUE = 'hardcopy_due';

    public const GRANTED = 'granted';

    /** Card order and labels. */
    public const LABELS = [
        self::DRAFTS => 'Drafts',
        self::IN_PROGRESS => 'In progress',
        self::HARDCOPY_DUE => 'Hardcopy due',
        self::GRANTED => 'Granted',
    ];

    /** Submitted and not yet granted or ended. */
    public const OPEN = [
        S::SUBMITTED, S::CMO_INTAKE, S::IN_REVIEW, S::READY_FOR_FILE, S::FILE_PREPARED,
        S::WITH_LEGAL, S::WITH_PS, S::RETURNED_TO_CMO, S::NON_OBJECTION_GRANTED,
    ];

    public const GRANTED_STATUSES = [S::LETTER_ISSUED, S::CLOSED];

    /** The group of one request; null for WITHDRAWN and REJECTED_FINAL. */
    public static function of(S $status, bool $hardcopyReceived): ?string
    {
        return match (true) {
            $status === S::DRAFT => self::DRAFTS,
            in_array($status, self::GRANTED_STATUSES, true) => self::GRANTED,
            in_array($status, self::OPEN, true) => $hardcopyReceived ? self::IN_PROGRESS : self::HARDCOPY_DUE,
            default => null,
        };
    }

    /** Limits $query (cases) to one group. */
    public static function apply(Builder $query, string $group): Builder
    {
        return match ($group) {
            self::DRAFTS => $query->where('cases.status', S::DRAFT->value),
            self::GRANTED => $query->whereIn('cases.status', self::values(self::GRANTED_STATUSES)),
            self::HARDCOPY_DUE => $query->whereIn('cases.status', self::values(self::OPEN))->whereNull('cases.hardcopy_received_on'),
            self::IN_PROGRESS => $query->whereIn('cases.status', self::values(self::OPEN))->whereNotNull('cases.hardcopy_received_on'),
        };
    }

    /**
     * Every group's count in ONE grouped query over $query (the caller scopes
     * it to the organisation). Groups with no requests count 0.
     *
     * @return array<string, int>
     */
    public static function counts(Builder $query): array
    {
        // The status values are the enum's own constants, never input, so they are
        // inlined: SQL Server needs the GROUP BY expression to match the SELECT exactly.
        $in = fn (array $statuses) => "cases.status IN ('".implode("','", self::values($statuses))."')";
        $group = 'CASE'
            ." WHEN cases.status = '".S::DRAFT->value."' THEN '".self::DRAFTS."'"
            .' WHEN '.$in(self::GRANTED_STATUSES)." THEN '".self::GRANTED."'"
            .' WHEN '.$in(self::OPEN)." AND cases.hardcopy_received_on IS NULL THEN '".self::HARDCOPY_DUE."'"
            .' WHEN '.$in(self::OPEN)." THEN '".self::IN_PROGRESS."'"
            .' END';

        $rows = $query->toBase()
            ->selectRaw("{$group} AS request_group, COUNT(*) AS request_count")
            ->groupByRaw($group)
            ->pluck('request_count', 'request_group');

        $counts = [];
        foreach (array_keys(self::LABELS) as $key) {
            $counts[$key] = (int) ($rows[$key] ?? 0);
        }

        return $counts;
    }

    /** @param  list<S>  $statuses */
    private static function values(array $statuses): array
    {
        return array_map(fn (S $s) => $s->value, $statuses);
    }
}
