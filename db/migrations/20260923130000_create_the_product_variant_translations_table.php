<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

/**
 * A variant's OWN description, per website language (MODULES.md, "Shop").
 *
 * Same shape as product_translations (20260918200000): one row per variant
 * per language, and a language without words has no row. That absence is the
 * point: a variant without a row in a language shows the product's
 * description in that language, so a later change to the product's text still
 * reaches every variant that never asked for text of its own. Nothing is
 * copied here, and no row is written for any existing variant.
 */
final class CreateTheProductVariantTranslationsTable extends AbstractMigration
{
    public function up(): void
    {
        if (
            $this->hasTable('product_variant_translations')
            || !$this->hasTable('product_variants')
            || !$this->hasTable('site_languages')
        ) {
            return;
        }

        $this->table('product_variant_translations', ['id' => true])
            ->addColumn('variant_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('language_code', 'string', [
                'limit' => 12,
                'null' => false,
                'encoding' => 'ascii',
                'collation' => 'ascii_bin',
                'comment' => 'site_languages.code',
            ])
            ->addColumn('description', 'text', [
                'limit' => MysqlAdapter::TEXT_MEDIUM,
                'null' => true,
                'default' => null,
                'comment' => 'The variant\'s own description in this language; no row means the product\'s description',
            ])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['variant_id', 'language_code'], ['unique' => true, 'name' => 'uq_product_variant_translations_owner_language'])
            ->addIndex(['language_code'], ['name' => 'idx_product_variant_translations_language'])
            ->addForeignKey('variant_id', 'product_variants', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_product_variant_translations_owner',
            ])
            ->addForeignKey('language_code', 'site_languages', 'code', [
                'delete' => 'RESTRICT',
                'update' => 'RESTRICT',
                'constraint' => 'fk_product_variant_translations_language',
            ])
            ->create();
    }

    /** Forward-only (db/migrations/CLAUDE.md). */
    public function down(): void
    {
    }
}
