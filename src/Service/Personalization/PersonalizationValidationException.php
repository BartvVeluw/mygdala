<?php

declare(strict_types=1);

namespace App\Service\Personalization;

/**
 * A submitted personalization that the server refuses. The message is
 * customer-facing and is passed straight through as the checkout's `error`
 * value, so it is written in English like every other api/checkout.php
 * message and never contains a technical detail, a path or an id.
 */
class PersonalizationValidationException extends \RuntimeException
{
}
