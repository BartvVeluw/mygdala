<?php

declare(strict_types=1);

namespace App\Service\Secrets;

/**
 * Why a secret could not be stored or read, as one of four reasons a screen
 * can explain in its own words:
 *
 *   NO_KEY           there is no application key yet: no APP_KEY and no key
 *                    file. Nothing was ever stored, or the key file is gone
 *   KEY_INVALID      APP_KEY or the key file exists but is not a key
 *   KEY_NOT_CREATED  a new key file could not be written to private storage
 *   UNREADABLE       a stored secret was sealed with another key, or its
 *                    bytes were changed; it has to be entered again
 *
 * The message never contains a secret or key material.
 */
final class SecretStoreException extends \RuntimeException
{
    public const NO_KEY = 'no_key';
    public const KEY_INVALID = 'key_invalid';
    public const KEY_NOT_CREATED = 'key_not_created';
    public const UNREADABLE = 'unreadable';

    public function __construct(
        public readonly string $reason,
        string $detail = '',
    ) {
        parent::__construct('[' . $reason . ']' . ($detail !== '' ? ' ' . $detail : ''));
    }
}
