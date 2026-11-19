<?php

namespace App\Console\Commands;

use App\Support\Preflight\Preflight as Checks;
use Illuminate\Console\Command;

/**
 * FIX-2 (5): go-live checks. READ-ONLY: never changes data, files or caches.
 * Prints PASS, WARN or FAIL per check, never a secret; exits 1 on any FAIL.
 */
class Preflight extends Command
{
    protected $signature = 'app:preflight';

    protected $description = 'Check this installation is ready to go live (read-only)';

    public function handle(Checks $checks): int
    {
        $results = $checks->run();

        foreach ($results as $r) {
            $tag = match ($r['status']) {
                Checks::PASS => '<fg=green>PASS</>',
                Checks::WARN => '<fg=yellow>WARN</>',
                default => '<fg=red>FAIL</>',
            };
            $this->line(sprintf('%s  %-28s %s', $tag, $r['check'], $r['detail']));
        }

        $counts = array_count_values(array_column($results, 'status')) + [Checks::PASS => 0, Checks::WARN => 0, Checks::FAIL => 0];
        $this->newLine();
        $this->line(sprintf('%d passed, %d warnings, %d failed', $counts[Checks::PASS], $counts[Checks::WARN], $counts[Checks::FAIL]));

        return Checks::hasFailure($results) ? self::FAILURE : self::SUCCESS;
    }
}
