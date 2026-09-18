<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

/**
 * Multilingual 2.0, phase 5 wave A: the words of the Portfolio module, one
 * row per owner per website language (docs/multilingual/ARCHITECTURE.md).
 *
 *   portfolio_category_translations    name                     of a category
 *   portfolio_item_translations        title, subtitle, alt,
 *                                      intro, description       of an item
 *   portfolio_item_image_translations  alt                      of an extra photo
 *
 * TYPED TABLES, like page_translations and the phase 4 tables: a portfolio
 * item, its category and its photos are real domain entities, so their words
 * get real columns rather than the generic block store. Every table has the
 * shape App\Service\Language\TranslationTable declares:
 *
 *   - the owner is a foreign key with ON DELETE CASCADE: words have no
 *     meaning without their row, and deleting an item already takes its
 *     photos (and so their alt texts) along;
 *   - the language is a foreign key on site_languages.code with ON DELETE
 *     RESTRICT, the same type as that column (ascii, ascii_bin, 12 wide):
 *     deleting a language that still has words is refused, never cascaded;
 *   - UNIQUE(owner, language_code): one row per owner per language, the
 *     default language included. A language without words has no row.
 *
 * WHAT IS NOT HERE, on purpose: the slug of a category or an item, the image
 * and thumbnail paths, the linked page, the categories an item has, whether
 * it is active or featured, and every sort order. They are the same in every
 * language and stay on portfolio_categories/portfolio_gallery_items/
 * portfolio_item_images. A category slug is generated once from its name and
 * never renamed; localized addresses are the routing phase's business.
 *
 * `intro` and `description` are the OLD project page's rich text, and `alt`
 * on portfolio_item_image_translations that page's photos. Nothing edits them
 * any more, but /portfolio/<slug> still shows them where an item has no
 * page of its own yet (App\Service\PortfolioGalleryContent), so their words
 * move like any other and stay readable per language.
 *
 * NOT A MODULE QUESTION. These tables are created whether the Portfolio
 * module is on or off: a switched-off module keeps its content, and a fresh
 * install with it off must end on the same schema as one with it on.
 *
 * SCHEMA ONLY. The words arrive with 20260918170000, in the same commit that
 * switches every reader over and drops the old columns.
 */
final class CreateThePortfolioTranslationTables extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('site_languages')) {
            return;
        }

        // Each name written out in $this->table('…'), so the schema check in
        // Tests\Install\MigrationTableNamesTest can see it being created.
        if (!$this->hasTable('portfolio_category_translations') && $this->hasTable('portfolio_categories')) {
            $this->create(
                $this->table('portfolio_category_translations', ['id' => true]),
                'portfolio_categories',
                'portfolio_category_id',
                ['name' => 100]
            );
        }

        if (!$this->hasTable('portfolio_item_translations') && $this->hasTable('portfolio_gallery_items')) {
            $this->create(
                $this->table('portfolio_item_translations', ['id' => true]),
                'portfolio_gallery_items',
                'portfolio_item_id',
                ['title' => 150, 'subtitle' => 150, 'alt' => 255, 'intro' => 0, 'description' => 0]
            );
        }

        if (!$this->hasTable('portfolio_item_image_translations') && $this->hasTable('portfolio_item_images')) {
            $this->create(
                $this->table('portfolio_item_image_translations', ['id' => true]),
                'portfolio_item_images',
                'portfolio_item_image_id',
                ['alt' => 255]
            );
        }
    }

    /**
     * One typed translation table. A field's length is the length of the
     * column it replaces; 0 means the old column held rich text, and the new
     * one is MEDIUMTEXT like block_translations.value, so no value that fit
     * in the old TEXT column can fail to fit here.
     *
     * @param array<string, int> $fields field => maximum length, 0 for rich text
     */
    private function create(\Phinx\Db\Table $table, string $ownerTable, string $ownerColumn, array $fields): void
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
            ]);

        foreach ($fields as $field => $maxLength) {
            $table->addColumn($field, $maxLength === 0 ? 'text' : 'string', [
                'limit' => $maxLength === 0 ? MysqlAdapter::TEXT_MEDIUM : $maxLength,
                'null' => true,
                'default' => null,
                'comment' => 'The words in this language; a language without words has no row',
            ]);
        }

        $table
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
     * Forward-only (db/migrations/CLAUDE.md). Once 20260918170000 has run,
     * these tables are where the Portfolio's words live.
     */
    public function down(): void
    {
    }
}
