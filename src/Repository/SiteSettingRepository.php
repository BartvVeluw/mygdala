<?php

namespace App\Repository;

/**
 * All site_settings SQL lives here. See App\Service\SiteSettings for the
 * defaults/fallback layer built on top of this.
 */
class SiteSettingRepository extends Repository
{
    /**
     * @return array<string, string> setting_key => setting_value
     */
    public function findAll(): array
    {
        $stmt = $this->db->query('SELECT setting_key, setting_value FROM site_settings');

        $settings = [];
        foreach ($stmt->fetchAll() as $row) {
            $settings[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
        }

        return $settings;
    }

    /**
     * Inserts or updates each given key. Used by the admin settings form,
     * which always submits every known key together.
     *
     * @param array<string, string> $values setting_key => setting_value
     */
    public function upsertMany(array $values): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO site_settings (setting_key, setting_value, created_at, updated_at)
             VALUES (:setting_key, :setting_value, NOW(), NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        );

        foreach ($values as $key => $value) {
            $stmt->execute(['setting_key' => $key, 'setting_value' => $value]);
        }
    }
}
