<?php

namespace App\Support;

/**
 * A "contains" LIKE match whose wildcards are literal: %, _ and [ (SQL Server)
 * in the search are escaped with \. Used by the vendor Requests search and the
 * dashboard queue search (DASH-1), so both escape the same way.
 */
final class SqlLike
{
    public static function containsPattern(string $search): string
    {
        return '%'.str_replace(['\\', '%', '_', '['], ['\\\\', '\\%', '\\_', '\\['], $search).'%';
    }

    /**
     * Arguments for whereRaw: "{column} LIKE ? ESCAPE '\'" with the escaped pattern.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function contains(string $column, string $search): array
    {
        return ["{$column} LIKE ? ESCAPE '\\'", [self::containsPattern($search)]];
    }
}
