<?php

namespace App\Documents\Scanning;

/** Outcome of one scan: clean, infected (with signature) or error (with message). */
final readonly class ScanResult
{
    public const CLEAN = 'clean';

    public const INFECTED = 'infected';

    public const ERROR = 'error';

    private function __construct(public string $state, public ?string $detail = null) {}

    public static function clean(): self
    {
        return new self(self::CLEAN);
    }

    public static function infected(string $signature): self
    {
        return new self(self::INFECTED, $signature);
    }

    public static function error(string $message): self
    {
        return new self(self::ERROR, $message);
    }

    public function isClean(): bool
    {
        return $this->state === self::CLEAN;
    }
}
