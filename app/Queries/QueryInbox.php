<?php

namespace App\Queries;

use App\Enums\CaseStatus;
use App\Enums\QueryStatus;
use App\Http\Resources\Cases\StaffCaseSummary;
use App\Models\CaseQuery;
use App\Models\ProcurementCase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * QUERIES-1: the queries waiting on a user, by the predicates the Queries tab,
 * the Next Step card and the work queue use (QueryAccess::forwardable,
 * needsAnswer). One place for "waiting on you": the work queue groups these
 * rows per case; the Queries page and the sidebar count list them per query.
 *
 * - Forward: a held message the CMO's desk may forward now.
 * - Answer: an open query on the user's side as recipient, delivered to them.
 *
 * Named officers (WORKFLOW-2) reach a query without seeing its case; the page
 * includes them, the work queue keeps its case-visibility rule.
 */
final class QueryInbox
{
    public const FORWARD = 'query_forward';

    public const ANSWER = 'query_answer';

    /** The most open queries looked at in one pass (the work queue's case cap). */
    public const LIMIT = 300;

    /**
     * @return list<array{case: ProcurementCase, query: CaseQuery, step: string, since: CarbonImmutable|null}>
     */
    public static function due(User $user, bool $includeNamed = true): array
    {
        $queries = CaseQuery::withoutGlobalScopes()
            ->where('status', '<>', QueryStatus::CLOSED->value)
            ->with('messages')
            ->orderBy('created_at')
            ->limit(self::LIMIT)
            ->get();

        $cases = ProcurementCase::withoutGlobalScopes()
            ->with(['organisation:id,name', 'requisition:id,case_id,item_description'])
            ->whereIn('id', $queries->pluck('case_id')->unique()->values()->all())
            ->get()->keyBy('id');

        $due = [];
        foreach ($queries as $query) {
            $case = $cases->get($query->case_id);
            if ($case === null || ! self::reaches($user, $case, $query, $includeNamed)) {
                continue;
            }

            $held = QueryAccess::forwardable($user, $case, $query);
            $found = match (true) {
                $held !== null => [self::FORWARD, $held->created_at],
                QueryAccess::needsAnswer($user, $case, $query) => [self::ANSWER, ($last = $query->messages->last())?->forwarded_at ?? $last?->created_at],
                default => null,
            };
            if ($found === null) {
                continue;
            }

            [$step, $since] = $found;
            $due[] = [
                'case' => $case,
                'query' => $query,
                'step' => $step,
                'since' => $since !== null ? CarbonImmutable::parse($since)->utc() : null,
            ];
        }

        return $due;
    }

    /** The sidebar badge: how many queries wait on $user. */
    public static function count(User $user): int
    {
        return count(self::due($user));
    }

    /**
     * The Queries page rows, oldest first. Vendors get the vendor wording only
     * (QueryView::forVendor): no office names, no organisation, no message text.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(User $user): array
    {
        $vendor = $user->isVendor();
        $today = CarbonImmutable::now(StaffCaseSummary::TIMEZONE)->startOfDay();

        $rows = array_map(function (array $d) use ($vendor, $today) {
            /** @var ProcurementCase $case */
            $case = $d['case'];
            /** @var CaseQuery $query */
            $query = $d['query'];
            $since = $d['since'];

            return [
                'id' => (string) $query->getKey(),
                'case_id' => (string) $case->getKey(),
                'step' => $d['step'],
                'action' => $vendor ? 'Reply' : ($d['step'] === self::FORWARD ? 'Forward Query' : 'Answer Query'),
                'title' => $vendor
                    ? 'Query From the '.QueryWording::MINISTRY
                    : $query->type->label().': '.QueryWording::office($query->raised_by_role, $case).' to '.QueryWording::office($query->raised_to_role, $case),
                'reference_no' => $case->reference_no,
                'item_description' => $case->requisition?->item_description !== null ? Str::limit($case->requisition->item_description, 80) : null,
                'organisation' => $vendor ? null : $case->organisation?->name,
                'waiting_since' => $since?->toIso8601ZuluString(),
                'days_waiting' => $since !== null ? (int) $since->setTimezone(StaffCaseSummary::TIMEZONE)->startOfDay()->diffInDays($today) : null,
                'url' => $vendor
                    ? route('requests.show', $case->getKey()).'#vendor-query-'.$query->getKey()
                    : route('cases.show', [$case->getKey(), 'tab' => 'queries']),
            ];
        }, self::due($user));

        usort($rows, fn (array $a, array $b) => [$a['waiting_since'] === null, $a['waiting_since']] <=> [$b['waiting_since'] === null, $b['waiting_since']]);

        return $rows;
    }

    /** Can $user reach $query: sees the case, or (staff, $includeNamed) was named on a forward. */
    private static function reaches(User $user, ProcurementCase $case, CaseQuery $query, bool $includeNamed): bool
    {
        if (QueryAccess::canSeeCase($user, $case)) {
            return true;
        }

        return $includeNamed
            && $case->status !== CaseStatus::DRAFT
            && QueryAccess::namedSide($user, $query) !== null;
    }
}
