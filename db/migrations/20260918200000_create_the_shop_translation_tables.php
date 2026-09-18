<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

/**
 * Multilingual 2.0, phase 5 wave C: the visitor-facing words of the Shop, one
 * row per owner per website language (docs/multilingual/ARCHITECTURE.md,
 * MODULES.md "Shop").
 *
 *   product_translations     name, description, meta_title, meta_description
 *   collection_translations  name, description, meta_title, meta_description,
 *                            related_heading
 *
 * TYPED TABLES, the same shape as every other one since phase 2
 * (App\Service\Language\TranslationTable):
 *
 *   - the owner is a foreign key with ON DELETE CASCADE;
 *   - the language is a foreign key on site_languages.code with ON DELETE
 *     RESTRICT, the same type as that column (ascii, ascii_bin, 12 wide);
 *   - UNIQUE(owner, language_code): one row per owner per language.
 *
 * WHAT IS NOT HERE, and this is the whole point of the wave: NOTHING THAT IS
 * IDENTITY, MONEY OR STOCK. A product keeps its id, its slug, its price, its
 * stock, its shipping settings, its `active`/`in_shop` flags, its image paths
 * and every relation on `products`; a collection keeps its id, slug, images,
 * `is_active`, `show_related_products` and `sort_order`. Switching language
 * changes what a visitor READS and never what the shop charges, looks up or
 * puts in a cart.
 *
 * NEITHER IS AN ORDER. `order_items.product_name` is a SNAPSHOT of what a
 * product was called at the moment of purchase, not a translation of what it
 * is called now, and it gets a store of its own in 20260918220000 with a rule
 * of its own. Nothing in these two tables is ever read for a historical order.
 *
 * `description` is the only RICH field of either (App\Service\DescriptionSanitizer,
 * as before) and gets MEDIUMTEXT, like block_translations.value, so no value
 * that fit in the old TEXT column can fail to fit here.
 *
 * NOT A MODULE QUESTION. These tables are created whether the Shop is on or
 * off: a switched-off module keeps its content, and a fresh install with it
 * off must end on the same schema as one with it on.
 *
 * SCHEMA ONLY. The words arrive with 20260918210000, in the same commit that
 * switches every reader over and drops the old columns.
 */
final class CreateTheShopTranslationTables extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('site_languages')) {
            return;
        }

        // Each name written out in $this->table('…'), so the schema check in
        // Tests\Install\MigrationTableNamesTest can see it being created.
        if (!$this->hasTable('product_translations') && $this->hasTable('products')) {
            $this->create(
                $this->table('product_translations', ['id' => true]),
                'products',
                'product_id',
                ['name' => 150, 'description' => 0, 'meta_title' => 255, 'meta_description' => 500]
            );
        }

        if (!$this->hasTable('collection_translations') && $this->hasTable('collections')) {
            $this->create(
                $this->table('collection_translations', ['id' => true]),
                'collections',
                'collection_id',
                ['name' => 150, 'description' => 0, 'meta_title' => 255, 'meta_description' => 500, 'related_heading' => 255]
            );
        }
    }

    /**
     * One typed translation table. A field's length is the length of the
     * column it replaces; 0 means the field is rich text, and the column is
     * MEDIUMTEXT.
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
     * Forward-only (db/migrations/CLAUDE.md). Once 20260918210000 has run,
     * these tables are where the Shop's words live.
     */
    public function down(): void
    {
    }
}
