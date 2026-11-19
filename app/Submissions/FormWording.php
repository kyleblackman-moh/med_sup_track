<?php

namespace App\Submissions;

/**
 * FORM-SAVE-1: the request form's status and summary wording, written by the
 * server (the browser only displays it). Sentence case for statuses, Title
 * Case for headings (design.md section 4).
 */
final class FormWording
{
    /** A partial draft save (A2). */
    public static function partialSave(int $notSaved): string
    {
        return $notSaved === 1
            ? 'Saved. 1 field needs attention and was not saved yet.'
            : "Saved. {$notSaved} fields need attention and were not saved yet.";
    }

    /** The error summary's heading when Review and Submit is blocked (A5d). */
    public static function issueHeading(int $count): string
    {
        return $count === 1
            ? '1 Thing Needs Fixing Before You Can Submit'
            : "{$count} Things Need Fixing Before You Can Submit";
    }

    /** In-app navigation with unsaved edits (A4). */
    public const LEAVE_UNSAVED = 'You have changes that are not saved yet. Leave anyway?';

    /** A type change went through while other edits could not be saved (A3: network or server error). */
    public const TYPE_SAVED_OTHERS_PENDING = 'The request type was saved. Your other changes are not saved yet because the connection failed; they will be saved when you try again.';

    /**
     * What a draft save left out, in form order: each with its section's label
     * (for the summary "Section: message") and the message.
     *
     * @param  array<string, string>  $dropped  key => message
     * @return list<array{key: string, section: string, section_label: string, message: string}>
     */
    public static function notSaved(array $dropped): array
    {
        $order = array_flip(RequestSections::ORDER);
        $items = [];
        foreach ($dropped as $key => $message) {
            $section = RequestSections::sectionOf($key) ?? 'item_tender';
            $items[] = [
                'key' => $key,
                'section' => $section,
                'section_label' => RequestSections::LABELS[$section],
                'message' => $message,
            ];
        }

        // Form order by section; within a section, the order the payload had (stable sort).
        usort($items, fn (array $a, array $b) => $order[$a['section']] <=> $order[$b['section']]);

        return $items;
    }
}
