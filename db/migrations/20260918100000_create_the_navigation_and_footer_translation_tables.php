<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Multilingual 2.0, phase 4 wave A: the words of the header menu and the
 * footer, one row per owner per website language
 * (docs/multilingual/ARCHITECTURE.md).
 *
 *   nav_item_translations       label   of a menu link, submenu item or header button
 *   footer_column_translations  title   of a footer column
 *   footer_link_translations    label   of a footer link
 *
 * TYPED TABLES, like page_translations: menu items and footer rows are real
 * domain entities, so their words get real columns rather than the generic
 * block store. Every table has the same shape
 * (App\Service\Language\TranslationTable):
 *
 *   - the owner is a foreign key with ON DELETE CASCADE: a label has no
 *     meaning without its item, and deleting a footer column already takes
 *     its links (and so their labels) along;
 *   - the language is a foreign key on site_languages.code with ON DELETE
 *     RESTRICT, the same type as that column (ascii, ascii_bin, 12 wide):
 *     deleting a language that still has words is refused, never cascaded;
 *   - UNIQUE(owner, language_code): one row per owner per language, the
 *     default language included. A language without words has no row.
 *
 * WHAT IS NOT HERE, on purpose: destination, page id, route, URL, action,
 * presentation, button variant, parent, order and visibility. They are the
 * same in every language and stay on nav_items/footer_columns/footer_links.
 *
 * SCHEMA ONLY. The words arrive with 20260918110000, in the same commit that
 * switches every reader over and drops the old columns.
 */
final class CreateTheNavigationAndFooterTranslationTables extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('site_languages')) {
            return;
        }

        // Each name written out in $this->table('…'), so the schema check in
        // Tests\Install\MigrationTableNamesTest can see it being created.
        if (!$this->hasTable('nav_item_translations') && $this->hasTable('nav_items')) {
            $this->create($this->table('nav_item_translations', ['id' => true]), 'nav_items', 'nav_item_id', 'label');
        }
        if (!$this->hasTable('footer_column_translations') && $this->hasTable('footer_columns')) {
            $this->create($this->table('footer_column_translations', ['id' => true]), 'footer_columns', 'footer_column_id', 'title');
        }
        if (!$this->hasTable('footer_link_translations') && $this->hasTable('footer_links')) {
            $this->create($this->table('footer_link_translations', ['id' => true]), 'footer_links', 'footer_link_id', 'label');
        }
    }

    /** One typed translation table with a single 100-character field, the length of the column it replaces. */
    private function create(\Phinx\Db\Table $table, string $ownerTable, string $ownerColumn, string $field): void
    {
        $name = $table->getName();

        $table
            ->addColumn($ownerColumn, 'integer', [
                'signed' => false,
                'null' => false,
            ])
            ->addColumn('language_code', 'string', [
                'limit' => 12,
                'null' => false,
                'encoding' => 'ascii',
                'collation' => 'ascii_bin',
                'comment' => 'site_languages.code',
            ])
            ->addColumn($field, 'string', [
                'limit' => 100,
                'null' => true,
                'default' => null,
                'comment' => 'The words in this language; a language without words has no row',
            ])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex([$ownerColumn, 'language_code'], ['unique' => true, 'name' => 'uq_' . $name . '_owner_language'])
            ->addIndex(['language_code'], ['name' => 'idx_' . $name . '_language'])
            ->addForeignKey($ownerColumn, $ownerTable, 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_' . $name . '_owner',
            ])
            ->addForeignKey('language_code', 'site_languages', 'code', [
                'delete' => 'RESTRICT',
                'update' => 'RESTRICT',
                'constraint' => 'fk_' . $name . '_language',
            ])
            ->create();
    }

    /**
     * Forward-only (db/migrations/CLAUDE.md). Once 20260918110000 has run,
     * these tables are where the menu and footer words live.
     */
    public function down(): void
    {
    }
}
