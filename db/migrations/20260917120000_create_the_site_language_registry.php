<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Multilingual 2.0, phase 1: the registry of WEBSITE languages
 * (docs/multilingual/ARCHITECTURE.md).
 *
 * One row per language the website can publish content in, and the single
 * place that says which of them is the default. Read through
 * App\Service\Language\SiteLanguages; every write goes through
 * App\Repository\SiteLanguageRepository.
 *
 * NOT the CMS interface language. That stays a preference per administrator
 * on `admin_users.interface_language` (App\Service\Language\AdminLocale), and
 * nothing here reads or writes it.
 *
 * THE SCHEMA, and why it looks like this on MySQL 5.7:
 *
 *   code         ascii_bin, UNIQUE. Case-sensitive and compact in an index.
 *                12 characters leave room for a region subtag later
 *                (`pt-br`); V1 itself only accepts two lowercase letters
 *                (App\Service\Language\LanguageCode).
 *   is_default   1 for the default language and NULL for every other row,
 *                never 0, under a UNIQUE index. MySQL allows any number of
 *                NULLs in a unique index but only one 1, so "at most one
 *                default" holds in the database itself. 5.7 has no partial
 *                index and does not enforce CHECK, and a trigger needs a
 *                privilege shared hosting does not always grant, so this is
 *                the strongest guarantee available there.
 *                "At least one" and "the default is active" are written into
 *                the repository's SQL: setDefault() refuses an inactive
 *                language, and deactivate() and delete() never match the
 *                default row.
 *   sort_order   the language order. Ties are broken by id, so the order is
 *                always the same.
 *
 * BOOTSTRAP. An existing installation publishes Dutch and English today, with
 * one of them as its primary language (`site_settings.primary_content_language`).
 * The registry gets exactly those two rows: the stored primary as the
 * default and first, the other one second, both active. No other language is
 * added. A missing or unknown stored value means Dutch, which is what
 * App\Service\Language\ContentLanguages::primary() already answered for it.
 * A fresh install runs the same code: 20260910140000 has written `nl` by then,
 * and the Setup Wizard moves the default afterwards through
 * SiteLanguages::setDefault().
 *
 * ONE SOURCE OF TRUTH. Once the registry holds an active default, the two
 * rows it replaces leave `site_settings`: `primary_content_language` (now
 * `site_languages.is_default`) and the long-deprecated
 * `enabled_content_languages`, whose order also encoded the primary. Keeping
 * them would leave a second answer that goes stale the first time an owner
 * saves the language screen. Their information is not lost: it is in the
 * registry.
 *
 * FORWARD-ONLY AND IDEMPOTENT. The table is created only when it is missing,
 * the rows only when the table is empty, and the settings rows are removed
 * only when a default exists. A second run changes nothing. No `_nl` or `_en`
 * column is touched.
 */
final class CreateTheSiteLanguageRegistry extends AbstractMigration
{
    private const TABLE = 'site_languages';

    private const LEGACY_PRIMARY = 'primary_content_language';
    private const LEGACY_ENABLED = 'enabled_content_languages';

    /**
     * The website languages of this date, written out rather than read from
     * App\Service\Language\LanguageRegistry: a migration records what
     * happened on the day it ran, and a later build that knows more languages
     * must not make this one insert them.
     */
    private const LANGUAGES = [
        'nl' => ['name' => 'Dutch', 'native_name' => 'Nederlands'],
        'en' => ['name' => 'English', 'native_name' => 'English'],
    ];

    private const FALLBACK_DEFAULT = 'nl';

    public function up(): void
    {
        $this->createTable();
        $this->bootstrapTheRegistry();
        $this->removeTheReplacedSettings();
    }

    /**
     * Deliberately empty, like the other settings migrations in this project.
     */
    public function down(): void
    {
    }

    private function createTable(): void
    {
        if ($this->hasTable(self::TABLE)) {
            return;
        }

        $this->table(self::TABLE, ['id' => true])
            ->addColumn('code', 'string', [
                'limit' => 12,
                'null' => false,
                'encoding' => 'ascii',
                'collation' => 'ascii_bin',
                'comment' => 'lowercase language code, validated by App\Service\Language\LanguageCode',
            ])
            ->addColumn('name', 'string', ['limit' => 64, 'null' => false, 'comment' => 'English name'])
            ->addColumn('native_name', 'string', ['limit' => 64, 'null' => false, 'comment' => 'name in the language itself'])
            ->addColumn('is_default', 'boolean', [
                'null' => true,
                'default' => null,
                'comment' => '1 = the default language, NULL = not; never 0 (unique index)',
            ])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['code'], ['unique' => true, 'name' => 'uq_site_languages_code'])
            ->addIndex(['is_default'], ['unique' => true, 'name' => 'uq_site_languages_default'])
            ->create();
    }

    private function bootstrapTheRegistry(): void
    {
        $existing = $this->fetchRow('SELECT COUNT(*) AS c FROM ' . self::TABLE);
        if ((int) $existing['c'] > 0) {
            return;
        }

        $default = $this->storedPrimary();

        $codes = array_merge(
            [$default],
            array_values(array_filter(
                array_keys(self::LANGUAGES),
                static fn (string $code): bool => $code !== $default
            ))
        );

        $placeholders = [];
        $parameters = [];
        $now = date('Y-m-d H:i:s');

        foreach ($codes as $position => $code) {
            $placeholders[] = '(?, ?, ?, ?, 1, ?, ?, ?)';
            array_push(
                $parameters,
                $code,
                self::LANGUAGES[$code]['name'],
                self::LANGUAGES[$code]['native_name'],
                $code === $default ? 1 : null,
                $position,
                $now,
                $now
            );
        }

        $this->execute(
            'INSERT INTO ' . self::TABLE
                . ' (code, name, native_name, is_default, is_active, sort_order, created_at, updated_at) VALUES '
                . implode(', ', $placeholders),
            $parameters
        );
    }

    /**
     * The primary language this installation stored, or Dutch when it stored
     * nothing this migration knows.
     */
    private function storedPrimary(): string
    {
        if (!$this->hasTable('site_settings')) {
            return self::FALLBACK_DEFAULT;
        }

        $row = $this->fetchRow(sprintf(
            "SELECT setting_value FROM site_settings WHERE setting_key = '%s'",
            self::LEGACY_PRIMARY
        ));

        $stored = is_array($row) ? trim((string) ($row['setting_value'] ?? '')) : '';

        return array_key_exists($stored, self::LANGUAGES) ? $stored : self::FALLBACK_DEFAULT;
    }

    private function removeTheReplacedSettings(): void
    {
        if (!$this->hasTable('site_settings')) {
            return;
        }

        // Only once the registry can answer the question these rows answered.
        $defaults = $this->fetchRow(
            'SELECT COUNT(*) AS c FROM ' . self::TABLE . ' WHERE is_default = 1 AND is_active = 1'
        );
        if ((int) $defaults['c'] !== 1) {
            return;
        }

        $this->execute(sprintf(
            "DELETE FROM site_settings WHERE setting_key IN ('%s', '%s')",
            self::LEGACY_PRIMARY,
            self::LEGACY_ENABLED
        ));
    }
}
