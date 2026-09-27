<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Secrets an administrator stores in the CMS, encrypted at rest
 * (App\Service\Secrets\SecretStore; SETUP.md, "Geheimen in het CMS"). The
 * first ones are the Shop's Mollie API keys, entered on Shop → Betalingen.
 *
 *   slot        the secret's name, owned by the code that uses it
 *               ("shop.mollie.test_api_key"); one row per slot
 *   ciphertext  "v1:" and base64 of nonce + XChaCha20-Poly1305 ciphertext,
 *               sealed by the application key, which is never in the
 *               database (App\Service\Secrets\MasterKey)
 *   key_id      a fingerprint of the key that sealed it, which reveals
 *               nothing of that key: a value sealed by another key is
 *               recognised instead of failing to decrypt
 *   hint        what a screen may show instead of the value
 *               ("test_••••••••abcd"); never the value
 *
 * A table of its own, not rows in site_settings: that table is read on every
 * page and its values travel into templates.
 *
 * A new table with nothing to take over: no backfill and no fresh-install
 * guard. An installation whose Mollie key is in .env keeps it there; nothing
 * is moved (MODULES.md, "Betalingen"). Idempotent (db/migrations/CLAUDE.md).
 */
final class CreateSecretSettingsTable extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('secret_settings')) {
            return;
        }

        $this->table('secret_settings', ['id' => false, 'primary_key' => ['slot']])
            ->addColumn('slot', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('ciphertext', 'text', ['null' => false, 'comment' => 'App\Service\Secrets\MasterKey::seal(); never plaintext'])
            ->addColumn('key_id', 'string', ['limit' => 16, 'null' => false, 'comment' => 'Fingerprint of the application key that sealed it'])
            ->addColumn('hint', 'string', ['limit' => 32, 'null' => false, 'default' => '', 'comment' => 'What a screen may show instead of the value'])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('secret_settings')) {
            $this->table('secret_settings')->drop()->save();
        }
    }
}
