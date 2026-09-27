<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * All `secret_settings` SQL. Every row is a secret an administrator stored in
 * the CMS, sealed by App\Service\Secrets\SecretStore before it gets here:
 * this class never sees a plaintext value and has no way to produce one.
 *
 * A table of its own and not `site_settings`, on purpose: SiteSettings::all()
 * is read on every page and handed to templates, and sealed or not, a secret
 * has no business travelling with the site's name and colours.
 */
final class SecretSettingRepository extends Repository
{
    /**
     * @return array{slot: string, ciphertext: string, key_id: string, hint: string, updated_at: string}|null
     */
    public function find(string $slot): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT slot, ciphertext, key_id, hint, updated_at FROM secret_settings WHERE slot = :slot'
        );
        $stmt->execute(['slot' => $slot]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        return [
            'slot' => (string) $row['slot'],
            'ciphertext' => (string) $row['ciphertext'],
            'key_id' => (string) $row['key_id'],
            'hint' => (string) $row['hint'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /** Stores a sealed value in $slot, replacing whatever was there. */
    public function upsert(string $slot, string $ciphertext, string $keyId, string $hint): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO secret_settings (slot, ciphertext, key_id, hint, created_at, updated_at)
             VALUES (:slot, :ciphertext, :key_id, :hint, NOW(), NOW())
             ON DUPLICATE KEY UPDATE ciphertext = VALUES(ciphertext), key_id = VALUES(key_id),
                                     hint = VALUES(hint), updated_at = NOW()'
        );
        $stmt->execute([
            'slot' => $slot,
            'ciphertext' => $ciphertext,
            'key_id' => $keyId,
            'hint' => $hint,
        ]);
    }
}
