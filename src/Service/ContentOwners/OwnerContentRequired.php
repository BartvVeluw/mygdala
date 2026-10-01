<?php

declare(strict_types=1);

namespace App\Service\ContentOwners;

/**
 * A block change refused by OwnerContentGuard: it would leave an owner that
 * must keep content (RequiresContent) without a meaningful block. The message
 * is the owner's own, meant for the editor; OwnerContentGuard::messageFor()
 * hands it to an endpoint's failure path.
 */
final class OwnerContentRequired extends \DomainException
{
}
