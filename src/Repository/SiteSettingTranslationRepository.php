<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * All `site_setting_translations` SQL (db/migrations/20260918120000).
 *
 * Deliberately dumb: which keys exist, which language a reader gets and what
 * an empty value falls back to is App\Service\Language\LocalizedSettings's
 * business, and nothing else calls this class. The schema holds the rules no
 * caller can walk past: one row per key per language (UNIQUE) and only a
 * registered language (a foreign key on site_languages.code).
 *
 * ONE TABLE, SEVERAL CATALOGUES. The rows of Core's own website text and
 * those of a module's settings sit side by side here, each read by the
 * catalogue that owns its keys — which is why there is no "give me
 * everything" read: a caller asks for the keys it owns and gets nothing else.
 */
final class SiteSettingTranslationRepository extends Repository
{
    /**
     * The rows of the keys one catalogue owns.
     *
     * @param list<string> $keys
     * @return list<array{setting_key: string, language_code: string, value: string}>
     */
    public function findByKeys(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $stmt = $this->db->prepare(
            'SELECT setting_key, language_code, value FROM site_setting_translations
              WHERE setting_key IN (' . $placeholders . ')
              ORDER BY setting_key ASC, id ASC'
        );
        $stmt->execute(array_values($keys));

        return $stmt->fetchAll();
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
