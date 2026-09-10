<?php

namespace App\Repository;

/**
 * All `module_settings` SQL. See App\Module\ModuleSettings for the layer on
 * top of it — the same split App\Service\SiteSettings/SiteSettingRepository
 * and ThemeSettings/ThemeSettingRepository already use.
 *
 * Only modules somebody actually chose are stored. A missing row means "no
 * stored preference", which is what lets App\Module\ModuleConfig keep the
 * environment variable in front of it and the "enabled" default behind it.
 */
class ModuleSettingRepository extends Repository
{
    /**
     * @return array<string, string> setting_key => setting_value
     */
    public function findAll(): array
    {
        $stmt = $this->db->query('SELECT setting_key, setting_value FROM module_settings');

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
            'INSERT INTO module_settings (setting_key, setting_value, created_at, updated_at)
             VALUES (:setting_key, :setting_value, NOW(), NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        );

        foreach ($values as $key => $value) {
            $stmt->execute(['setting_key' => $key, 'setting_value' => $value]);
        }
    }
}
