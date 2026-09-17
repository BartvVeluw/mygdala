<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * All `site_setting_translations` SQL (db/migrations/20260918120000).
 *
 * Deliberately dumb: which keys exist, which language a reader gets and what
 * an empty value falls back to is App\Service\LocalizedSiteSettings's
 * business, and nothing else calls this class. The schema holds the rules no
 * caller can walk past: one row per key per language (UNIQUE) and only a
 * registered language (a foreign key on site_languages.code).
 */
final class SiteSettingTranslationRepository extends Repository
{
    /** @return list<array{setting_key: string, language_code: string, value: string}> */
    public function findAll(): array
    {
        return $this->db
            ->query('SELECT setting_key, language_code, value FROM site_setting_translations ORDER BY setting_key ASC, id ASC')
            ->fetchAll();
    }

    /**
     * One key in one language: a new row, or the existing row's words
     * replaced. One statement; takes part in an open transaction.
     */
    public function save(string $key, string $languageCode, string $value): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO site_setting_translations (setting_key, language_code, value, created_at, updated_at)
             VALUES (:setting_key, :language_code, :value, NOW(), NOW())
             ON DUPLICATE KEY UPDATE value = :value_update, updated_at = NOW()'
        );
        $stmt->execute([
            'setting_key' => $key,
            'language_code' => $languageCode,
            'value' => $value,
            'value_update' => $value,
        ]);
    }

    public function delete(string $key, string $languageCode): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM site_setting_translations WHERE setting_key = :setting_key AND language_code = :language_code'
        );
        $stmt->execute(['setting_key' => $key, 'language_code' => $languageCode]);
    }
}
