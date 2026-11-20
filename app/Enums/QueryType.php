<?php

namespace App\Enums;

/** OVERNIGHT-2 (D1): the query types the routes carry (queries.type). */
enum QueryType: string
{
    case TECHNICAL = 'TECHNICAL';
    case LEGAL = 'LEGAL';
    case PS_TECHNICAL = 'PS_TECHNICAL';
    case PS_LEGAL = 'PS_LEGAL';

    public function label(): string
    {
        return match ($this) {
            self::TECHNICAL => 'Technical Query',
            self::LEGAL => 'Legal Query',
            self::PS_TECHNICAL => 'PS Technical Query',
            self::PS_LEGAL => 'PS Legal-Related Query',
        };
    }
}
