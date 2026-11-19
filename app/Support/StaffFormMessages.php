<?php

namespace App\Support;

/**
 * FORM-SAVE-1 (A5e): the wording layer of the staff action forms (intake,
 * hardcopy, review, file, Legal, PS, route, letter), as the vendor form has
 * RequisitionMessages: fields named as the screen shows them, sentence case,
 * "needs" not "must", never "invalid", never a key. Each action's own
 * messages come first; these cover every other rule. No business rule here.
 */
final class StaffFormMessages
{
    /** Fallback messages per rule (size rules per type). */
    public const FALLBACK = [
        'required' => ':Attribute is required.',
        'required_if' => ':Attribute is required.',
        'string' => ':Attribute needs to be text.',
        'integer' => 'Choose :attribute from the list.',
        'exists' => 'Choose :attribute from the list.',
        'array' => 'Choose :attribute from the list.',
        'in' => 'Choose one of the options for :attribute.',
        'date_format' => ':Attribute needs to be a date.',
        'min' => [
            'array' => 'Choose at least :min for :attribute.',
            'string' => ':Attribute needs to be at least :min characters.',
            'numeric' => ':Attribute needs to be at least :min.',
        ],
        'max' => [
            'array' => 'Choose no more than :max for :attribute.',
            'string' => ':Attribute needs to be :max characters or fewer.',
            'numeric' => ':Attribute needs to be :max or less.',
        ],
    ];

    /** Field names as the staff screens show them. */
    public const ATTRIBUTES = [
        'technocrats' => 'Technocrats',
        'technocrats.*' => 'Technocrats',
        'cab_officers' => 'CAB Officers',
        'cab_officers.*' => 'CAB Officers',
        'legal_officers' => 'Legal Officers',
        'legal_officers.*' => 'Legal Officers',
        'reviewers' => 'Reviewers',
        'reviewers.*' => 'Reviewers',
        'instruction' => 'Instruction',
        'file_no' => 'File Number',
        'decision' => 'Decision',
        'return_kind' => 'Query Type',
        'reason' => 'Reason',
        'note' => 'Note',
        'destination' => 'Send To',
        'outcome' => 'Outcome',
        'letter_date' => 'Letter Date',
        'letter_reference' => 'Reference',
        'emailed_on' => 'Date Emailed',
        'hardcopy_sent_on' => 'Date Hardcopy Sent',
        'received_on' => 'Date Received',
    ];
}
