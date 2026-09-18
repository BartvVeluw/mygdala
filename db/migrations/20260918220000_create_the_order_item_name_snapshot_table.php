<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Multilingual 2.0, phase 5 wave C: the extra language versions of what a
 * product was CALLED at the moment it was bought
 * (docs/multilingual/ARCHITECTURE.md, MODULES.md "Shop").
 *
 *   order_item_translations  product_name
 *
 * THIS IS A SNAPSHOT, NOT A TRANSLATION, and the difference is the whole
 * reason it is a table of its own rather than a row in product_translations:
 *
 *   - a translation says what a product is called NOW, and changes when an
 *     editor changes it;
 *   - a snapshot says what it was called THEN, and must never change again —
 *     not when the product is renamed, not when it is deleted, and not when
 *     the site's default language moves.
 *
 * `order_items.product_name` STAYS EXACTLY WHERE IT IS and keeps its meaning:
 * the one language-free name every document prints. The invoice, the
 * confirmation e-mail and the CMS order screen read it and nothing else, so
 * no change to the language registry can ever alter a document that has
 * already been sent. This table holds only the OTHER language versions the
 * order-status page offers a visitor, and its reader is a rule of its own —
 * "the row for this language, else order_items.product_name" — never
 * App\Service\Language\LanguageFallback, which resolves against whatever the
 * default language is today.
 *
 * SHAPE. The same as every typed translation table
 * (App\Service\Language\TranslationTable), so one repository can write its
 * SQL: owner id, language_code, one field, UNIQUE(owner, language), FK on the
 * owner with ON DELETE CASCADE, FK on site_languages.code with ON DELETE
 * RESTRICT. An order line that is deleted takes its names with it; a language
 * that a historical order still names cannot be deleted, which is the correct
 * answer — a shop does not throw away what an invoice says.
 *
 * SCHEMA ONLY. The English names of existing orders arrive with
 * 20260918230000, in the same commit that switches the one reader over and
 * drops order_items.product_name_en.
 */
final class CreateTheOrderItemNameSnapshotTable extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('site_languages') || !$this->hasTable('order_items')) {
            return;
        }

        if ($this->hasTable('order_item_translations')) {
            return;
        }

        $this->table('order_item_translations', ['id' => true])
            ->addColumn('order_item_id', 'integer', [
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
            ->addColumn('product_name', 'string', [
                'limit' => 255,
                'null' => true,
                'default' => null,
                'comment' => 'What the product was called in this language AT THE MOMENT OF PURCHASE; never updated afterwards',
            ])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['order_item_id', 'language_code'], ['unique' => true, 'name' => 'uq_order_item_translations_owner_language'])
            ->addIndex(['language_code'], ['name' => 'idx_order_item_translations_language'])
            ->addForeignKey('order_item_id', 'order_items', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_order_item_translations_owner',
            ])
            ->addForeignKey('language_code', 'site_languages', 'code', [
                'delete' => 'RESTRICT',
                'update' => 'RESTRICT',
                'constraint' => 'fk_order_item_translations_language',
            ])
            ->create();
    }

    /** Forward-only (db/migrations/CLAUDE.md). */
    public function down(): void
    {
    }
}
