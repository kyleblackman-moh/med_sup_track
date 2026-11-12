<?php

namespace App\Submissions;

/**
 * Result of SubmissionReadiness::evaluate. Errors block submission; warnings
 * are advisory; pending names checks not evaluated yet.
 */
final readonly class ReadinessReport
{
    /**
     * @param  list<array{key: string, message: string}>  $errors
     * @param  list<array{key: string, message: string}>  $warnings
     * @param  list<string>  $pending
     */
    public function __construct(
        public array $errors,
        public array $warnings,
        public array $pending,
    ) {}

    public function isReady(): bool
    {
        return $this->errors === [];
    }
}
