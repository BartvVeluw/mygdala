<?php

declare(strict_types=1);

namespace App\Service\OrderFields;

/**
 * An upload for an "Afbeelding uploaden" question that cannot be taken. The
 * message is for the customer, in the language of the page; `kind` is the
 * reason in one word (too_large, type, …) for the page and the tests.
 */
final class OrderFieldUploadException extends \RuntimeException
{
    public function __construct(public readonly string $kind, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
