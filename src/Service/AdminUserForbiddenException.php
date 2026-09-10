<?php

namespace App\Service;

use RuntimeException;

/**
 * The signed-in user may manage CMS users in general, but not *this* one —
 * e.g. a users.manage holder trying to edit a Super Admin account. Distinct
 * from AdminUserValidationException because the answer is a 403, not a form
 * error the user could fix by typing something else.
 */
class AdminUserForbiddenException extends RuntimeException
{
}
