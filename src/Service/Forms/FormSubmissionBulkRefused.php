<?php

declare(strict_types=1);

namespace App\Service\Forms;

/**
 * A bulk request on stored submissions that was refused as a whole, before
 * anything changed (App\Service\Forms\FormSubmissionBulk). The reason is one
 * of the class constants, so the endpoint can answer it without parsing a
 * message, and so a message never carries anything a visitor wrote.
 */
final class FormSubmissionBulkRefused extends \RuntimeException
{
    /** No id at all: nothing was selected. */
    public const NOTHING_SELECTED = 'nothing_selected';

    /** More ids than one request may carry. */
    public const TOO_MANY = 'too_many';

    /** An entry that is not a positive whole number, or a form scope that is not one. */
    public const MALFORMED = 'malformed';

    /** An action outside the closed list. */
    public const UNKNOWN_ACTION = 'unknown_action';

    /** One or more ids that do not exist (any more). */
    public const NOT_FOUND = 'not_found';

    /** One or more submissions that belong to another form than the request's scope. */
    public const OUTSIDE_SCOPE = 'outside_scope';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('Bulk action refused: ' . $reason);
    }
}
