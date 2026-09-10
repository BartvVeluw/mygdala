<?php

declare(strict_types=1);

namespace App\Service\Translation;

/**
 * A translation could not be produced.
 *
 * Deliberately one exception type and not a hierarchy per failure mode: every
 * caller does the same thing with it — tell the editor the translation did
 * not happen and leave their text exactly as it was. The distinction that
 * matters is in the MESSAGE, and that message is written for an editor, not
 * for a log parser.
 *
 * Never carries the API key, the request body or the raw provider response.
 * An editor sees "the translation service refused the request"; the detail
 * goes to error_log() where it belongs.
 */
final class TranslationException extends \RuntimeException
{
}
