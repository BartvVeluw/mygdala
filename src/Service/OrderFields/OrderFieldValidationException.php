<?php

declare(strict_types=1);

namespace App\Service\OrderFields;

/**
 * An answer to a product's order question that cannot be taken: a required
 * question left empty, a text too long, a choice that is not one of the
 * question's. The message is written for the customer, in the language of
 * the request, and names the question; $fieldId says which one.
 */
final class OrderFieldValidationException extends \RuntimeException
{
    public function __construct(public readonly int $fieldId, string $message)
    {
        parent::__construct($message);
    }
}
