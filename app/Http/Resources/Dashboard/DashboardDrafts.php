<?php

namespace App\Http\Resources\Dashboard;

use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Http\Resources\Cases\StaffCaseSummary;
use App\Http\Resources\Requests\RequestSummaryResource;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Support\RequestGroups;

/**
 * ANALYTICS: the dashboard's "Your Drafts" card for users who submit requests
 * (case.submit): their own organisation's DRAFT cases, newest updated first.
 * Read through ProcurementCase's global scope (VisibleToUserScope), as the
 * vendor Requests list is, and built from RequestSummaryResource's vendor-safe
 * fields only: nothing internal is ever included. Read-only.
 */
final class DashboardDrafts
{
    public const MAX = 10;

    public const UNTITLED = 'Untitled Draft';

    /**
     * @return array{items: list<array{id: string, reference: string|null, title: string, type: string|null, updated: string, edit_href: string|null}>, total: int, requests_href: string}|null
     */
    public static function for(User $user): ?array
    {
        if (! $user->can(Permission::CaseSubmit->value)) {
            return null;
        }

        $query = ProcurementCase::query()
            ->where('cases.org_id', $user->org_id)
            ->where('cases.status', CaseStatus::DRAFT->value);

        $total = (clone $query)->count();
        $cases = $query->with(['procurementType', 'requisition'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::MAX)
            ->get();

        return [
            'items' => $cases->map(fn (ProcurementCase $case) => self::item($case, $user))->values()->all(),
            'total' => $total,
            'requests_href' => route('requests.index', ['group' => RequestGroups::DRAFTS], false),
        ];
    }

    /** @return array{id: string, reference: string|null, title: string, type: string|null, updated: string, edit_href: string|null} */
    private static function item(ProcurementCase $case, User $user): array
    {
        $fields = RequestSummaryResource::fields($case, $user);
        $type = $fields['procurement_type']['selected'] ? $fields['procurement_type']['name'] : null;
        $description = trim((string) ($fields['item_description'] ?? ''));

        return [
            'id' => (string) $fields['id'],
            'reference' => $fields['reference_no'],
            'title' => $description !== '' ? $description : ($type ?? self::UNTITLED),
            'type' => $type,
            'updated' => (string) $case->updated_at?->copy()->setTimezone(StaffCaseSummary::TIMEZONE)->format('j M Y, g:i a'),
            'edit_href' => $fields['can']['edit'] ? route('requests.edit', $case, false) : null,
        ];
    }
}
