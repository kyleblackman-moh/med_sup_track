<?php

namespace App\Workflow;

use App\Enums\CaseStatus as S;
use App\Workflow\Actions\RouteReturnedCase;

/**
 * NEXT-STEP-1: the ONE place for the staff-facing words of the Next Step card:
 * the stage name, who holds the file and the instruction sentence, keyed by
 * status, plus the destination names. React renders these strings and never
 * holds a status, holder or destination name of its own.
 *
 * Staff only: the vendor DTOs never carry any of it. Draft wording, to confirm
 * with the CMO's Office. {vendor} is replaced with the case's organisation name.
 */
final class StageWording
{
    public const CMO_OFFICE = "CMO's Office";

    public const TECHNOCRATS = 'Technocrats';

    public const CAB = 'CAB';

    public const TECHNOCRATS_AND_CAB = 'Technocrats and CAB';

    public const LEGAL_UNIT = 'Legal Unit';

    public const PS_OFFICE = 'PS Office';

    /** Stands for the case's vendor organisation, resolved by NextStep. */
    public const VENDOR = '{vendor}';

    /**
     * Every status: stage (Title Case), holder (null when nobody holds the
     * file) and the default instruction. A guard test fails if one is missing.
     *
     * @var array<string, array{stage: string, holder: ?string, instruction: string}>
     */
    public const STAGES = [
        S::DRAFT->value => [
            'stage' => 'Draft',
            'holder' => self::VENDOR,
            'instruction' => 'The vendor is still preparing this request.',
        ],
        S::SUBMITTED->value => [
            'stage' => 'Submitted',
            'holder' => self::CMO_OFFICE,
            'instruction' => 'The CMO Registry accepts the request into intake and records the hardcopy when it arrives.',
        ],
        S::CMO_INTAKE->value => [
            'stage' => 'CMO Intake',
            'holder' => self::CMO_OFFICE,
            'instruction' => 'Record the hardcopy when it arrives, then send the file to the Technocrats and CAB. Technical review and funding confirmation run at the same time.',
        ],
        S::IN_REVIEW->value => [
            'stage' => 'In Review',
            'holder' => self::TECHNOCRATS_AND_CAB,
            'instruction' => "The Technocrats consider the request and CAB confirms funding. When the hardcopy, technical consideration and funding are all done, the CMO's Office marks the file ready.",
        ],
        S::READY_FOR_FILE->value => [
            'stage' => 'Ready for File',
            'holder' => self::CMO_OFFICE,
            'instruction' => "The CMO's Office prepares the file and gives it a file number.",
        ],
        S::FILE_PREPARED->value => [
            'stage' => 'File Prepared',
            'holder' => self::CMO_OFFICE,
            'instruction' => "The file is prepared. The CMO's Office sends it to the Legal Unit.",
        ],
        S::WITH_LEGAL->value => [
            'stage' => 'With Legal',
            'holder' => self::LEGAL_UNIT,
            'instruction' => 'Waiting on the Legal Unit to confirm the contract or return the file with a query.',
        ],
        S::WITH_PS->value => [
            'stage' => 'With PS',
            'holder' => self::PS_OFFICE,
            'instruction' => 'Waiting on the PS Office to grant non-objection or return the file with a query.',
        ],
        S::NON_OBJECTION_GRANTED->value => [
            'stage' => 'Non-Objection Granted',
            'holder' => self::CMO_OFFICE,
            'instruction' => 'Non-objection is granted. Send the letter to {vendor} by email and hardcopy, then record it here.',
        ],
        S::LETTER_ISSUED->value => [
            'stage' => 'Letter Issued',
            'holder' => self::CMO_OFFICE,
            'instruction' => 'The letter is issued. Close the case when nothing else is needed.',
        ],
        S::CLOSED->value => [
            'stage' => 'Closed',
            'holder' => null,
            'instruction' => 'This case is closed. Nothing more is needed.',
        ],
        S::RETURNED_TO_CMO->value => [
            'stage' => 'Returned to CMO',
            'holder' => self::CMO_OFFICE,
            'instruction' => "The file came back to the CMO's Office. Choose where it goes next.",
        ],
        S::WITHDRAWN->value => [
            'stage' => 'Withdrawn',
            'holder' => null,
            'instruction' => 'This request was withdrawn. Nothing more is needed.',
        ],
        S::REJECTED_FINAL->value => [
            'stage' => 'Rejected (Final)',
            'holder' => null,
            'instruction' => 'This request was rejected. Nothing more is needed.',
        ],
    ];

    /** RETURNED_TO_CMO, by who sent it back (the from_status of the return). */
    public const RETURNED_BY = [
        S::WITH_LEGAL->value => 'The Legal Unit returned the file with a query. Forward it to the Technocrats, or choose another destination the rules allow.',
        S::WITH_PS->value => "The PS Office returned the file with a {kind}. Route it from the CMO's Office to the destination it needs.",
    ];

    /** Destination names for a returned case (RouteReturnedCase destinations). */
    public const ROUTE_DESTINATIONS = [
        RouteReturnedCase::TECHNOCRATS => self::TECHNOCRATS,
        RouteReturnedCase::LEGAL => self::LEGAL_UNIT,
        RouteReturnedCase::PS => self::PS_OFFICE,
    ];

    /** @return array{stage: string, holder: ?string, instruction: string} */
    public static function for(S $status, string $vendor): array
    {
        $entry = self::STAGES[$status->value];

        return [
            'stage' => $entry['stage'],
            'holder' => $entry['holder'] === null ? null : self::vendor($entry['holder'], $vendor),
            'instruction' => self::vendor($entry['instruction'], $vendor),
        ];
    }

    public static function vendor(string $text, string $vendor): string
    {
        return str_replace(self::VENDOR, $vendor, $text);
    }
}
