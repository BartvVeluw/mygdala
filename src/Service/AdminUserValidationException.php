<?php

namespace App\Service;

use RuntimeException;

/**
 * Every reason a CMS user could not be saved, collected in one throw so the
 * form can show them all at once instead of one per round trip — the same
 * shape the collection/product editors already flash into the session.
 *
 * Same idea as App\Service\Address\AddressValidationException, kept separate
 * because these messages are admin-facing rather than customer-facing.
 */
class AdminUserValidationException extends RuntimeException
{
    /** @var list<string> */
    private array $errors;

    /**
     * @param list<string> $errors
     */
    public function __construct(array $errors)
    {
        $this->errors = array_values($errors);

        parent::__construct($this->errors === [] ? 'Ongeldige invoer.' : (string) $this->errors[0]);
    }

    /**
     * @return list<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
