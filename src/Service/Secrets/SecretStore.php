<?php

declare(strict_types=1);

namespace App\Service\Secrets;

use App\Repository\SecretSettingRepository;

/**
 * Secrets an administrator enters in the CMS, encrypted at rest.
 *
 * A value is sealed by the application key (MasterKey, never in the
 * database) before it reaches the `secret_settings` table, and opened only
 * where it is used. What a screen may show of it is the `hint` its owner
 * stores next to it — for a Mollie key "test_••••••••abcd" — and never the
 * value: nothing here returns a stored secret to be printed.
 *
 * A SLOT is a secret's name, owned by the code that uses it
 * ("shop.mollie.test_api_key"): lower-case letters, digits, dots and
 * underscores. The slot is sealed into the value, so a value copied into
 * another slot does not open there.
 *
 * FAIL CLOSED. A stored value this installation's key cannot open — the key
 * file is gone, APP_KEY changed, the row was edited — is UNREADABLE: get()
 * throws and never returns something else in its place, and describe() says
 * so, so the screen can ask for the secret again. Storing a new value makes
 * the key if there is none yet (MasterKey::loadOrCreate()), and replaces
 * only its own slot.
 *
 * Generic on purpose (Core), so a later secret has a place to go; for now
 * only the Shop's Mollie keys live here (App\Service\Payment\MollieConfiguration).
 */
final class SecretStore
{
    private ?SecretSettingRepository $repository;

    /**
     * @param array<string, string>|null $environment a test's own variables for MasterKey; null reads .env and $_ENV
     */
    public function __construct(
        ?SecretSettingRepository $repository = null,
        private readonly ?array $environment = null,
    ) {
        $this->repository = $repository;
    }

    /**
     * Seals $value into $slot, replacing what was there.
     *
     * @param string $hint what a screen may show instead of the value; must not contain it
     *
     * @throws SecretStoreException when there is no usable key and none can be made
     */
    public function put(string $slot, #[\SensitiveParameter] string $value, string $hint): void
    {
        self::assertSlot($slot);

        $key = MasterKey::loadOrCreate($this->environment);
        $this->repository()->upsert($slot, $key->seal($slot, $value), $key->id(), mb_substr($hint, 0, 32));
    }

    /**
     * The value in $slot, or null when nothing is stored there.
     *
     * @throws SecretStoreException UNREADABLE (or KEY_INVALID) when something is stored but cannot be opened
     */
    public function get(string $slot): ?string
    {
        self::assertSlot($slot);

        $row = $this->repository()->find($slot);
        if ($row === null) {
            return null;
        }

        $key = MasterKey::load($this->environment);
        if ($key === null) {
            throw new SecretStoreException(SecretStoreException::UNREADABLE, 'a value is stored in ' . $slot . ' but there is no application key');
        }

        if (!hash_equals($key->id(), $row['key_id'])) {
            throw new SecretStoreException(SecretStoreException::UNREADABLE, 'the value in ' . $slot . ' was sealed by another application key');
        }

        return $key->open($slot, $row['ciphertext']);
    }

    /**
     * What a screen may know about $slot without the value: its hint, when
     * it was stored, and whether this installation can still open it. Null
     * when nothing is stored.
     *
     * @return array{hint: string, updated_at: string, readable: bool, reason: string}|null
     */
    public function describe(string $slot): ?array
    {
        self::assertSlot($slot);

        $row = $this->repository()->find($slot);
        if ($row === null) {
            return null;
        }

        $readable = true;
        $reason = '';
        try {
            $this->get($slot);
        } catch (SecretStoreException $e) {
            $readable = false;
            $reason = $e->reason;
        }

        return ['hint' => $row['hint'], 'updated_at' => $row['updated_at'], 'readable' => $readable, 'reason' => $reason];
    }

    private static function assertSlot(string $slot): void
    {
        if (preg_match('/^[a-z0-9_.]{1,64}$/', $slot) !== 1) {
            throw new \InvalidArgumentException('Invalid secret slot name.');
        }
    }

    private function repository(): SecretSettingRepository
    {
        return $this->repository ??= new SecretSettingRepository();
    }
}
