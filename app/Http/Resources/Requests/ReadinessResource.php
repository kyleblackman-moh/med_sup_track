<?php

namespace App\Http\Resources\Requests;

use App\Submissions\ReadinessReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vendor-facing submission readiness.
 *
 * Warnings about staff-set quotation fields (technical_status) are dropped: they
 * reveal an internal MOH assessment, which vendors must not see.
 *
 * @property ReadinessReport $resource
 */
class ReadinessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $report = $this->resource;

        return [
            'errors' => $report->errors,
            'warnings' => array_values(array_filter(
                $report->warnings,
                fn (array $warning) => ! str_ends_with($warning['key'], '.technical_status'),
            )),
            'pending' => $report->pending,
            'is_ready' => $report->isReady(),
        ];
    }
}
