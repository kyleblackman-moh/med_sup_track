<?php

namespace App\Submissions;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Allocates the next case reference, {prefix}-{year}-{000001}.
 *
 * Must run inside the caller's transaction: the year row is locked
 * (rowlock/updlock/holdlock on SQL Server) and incremented, so a failed submit
 * rolls the counter back and numbers stay gap-free.
 */
final class ReferenceNumberAllocator
{
    /** SQL Server duplicate key errors. */
    private const DUPLICATE_KEY = [2627, 2601];

    public function allocate(): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Reference numbers must be allocated inside a transaction.');
        }

        $year = (int) CarbonImmutable::now(config('cases.reference_timezone'))->year;
        $current = $this->lockedValue($year);

        if ($current === null) {
            try {
                // A savepoint, so a lost race does not spoil the outer transaction.
                DB::transaction(fn () => DB::table('reference_counters')->insert([
                    'year' => $year,
                    'last_number' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            } catch (QueryException $e) {
                if (! in_array((int) ($e->errorInfo[1] ?? 0), self::DUPLICATE_KEY, true)) {
                    throw $e;
                }
            }

            $current = $this->lockedValue($year)
                ?? throw new LogicException("Reference counter for {$year} could not be created.");
        }

        $next = $current + 1;
        DB::table('reference_counters')->where('year', $year)->update(['last_number' => $next, 'updated_at' => now()]);

        return sprintf('%s-%d-%06d', config('cases.reference_prefix'), $year, $next);
    }

    private function lockedValue(int $year): ?int
    {
        $value = DB::table('reference_counters')->where('year', $year)->lockForUpdate()->value('last_number');

        return $value === null ? null : (int) $value;
    }
}
