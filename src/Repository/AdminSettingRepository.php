<?php

namespace App\Repository;

/**
 * All `admin_settings` SQL. See App\Service\AdminTheme for the layer on top
 * of it — the same split App\Service\SiteSettings/SiteSettingRepository,
 * ThemeSettings/ThemeSettingRepository and ModuleSettings/
 * ModuleSettingRepository already use.
 *
 * Only a value somebody actually chose is stored: a missing row means "the
 * code default", which is what makes a fresh install and an installation
 * that predates this table both render the Default admin theme without a
 * backfill.
 */
class AdminSettingRepository extends Repository
{
    /**
     * @return array<string, string> setting_key => setting_value
     */
    public function findAll(): array
    {
        $stmt = $this->db->query('SELECT setting_key, setting_value FROM admin_settings');

        $settings = [];
        foreach ($stmt->fetchAll() as $row) {
            $settings[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
        }

        return $settings;
    }

    /**
     * @param array<string, string> $values setting_key => setting_value
     */
    public function upsertMany(array $values): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO admin_settings (setting_key, setting_value, created_at, updated_at)
             VALUES (:setting_key, :setting_value, NOW(), NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        );

        foreach ($values as $key => $value) {
            $stmt->execute(['setting_key' => $key, 'setting_value' => $value]);
        }
    }

    /**
     * Removes the given keys, so they fall back to their code default.
     *
     * @param list<string> $keys
     */
    public function deleteKeys(array $keys): void
    {
        if ($keys === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $this->db->prepare('DELETE FROM admin_settings WHERE setting_key IN (' . $placeholders . ')')
            ->execute(array_values($keys));
    }
}
