<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Moves the English half of every order line's product-name snapshot into
 * the table of 20260918220000, and drops the column (Multilingual 2.0
 * phase 5 wave C, docs/multilingual/ARCHITECTURE.md).
 *
 *   order_items.product_name_en  ->  order_item_translations.product_name  en
 *
 * ONE COLUMN, AND NOT THE OTHER ONE. `order_items.product_name` is not
 * touched: it is the language-free snapshot the invoice, the confirmation
 * e-mail and the CMS order screen print, and it stays exactly what it is. The
 * only thing that read `product_name_en` was api/admin/../order-status.php —
 * the public order-status page, which offers a visitor the English name
 * beside the Dutch one — and that page now reads this table with the rule
 * "the row for this language, else order_items.product_name".
 *
 * NOTHING IS REWRITTEN FROM A CURRENT PRODUCT NAME. A snapshot from 2024 says
 * what it said in 2024. This migration copies bytes and drops a column; it
 * never joins to `products`.
 *
 * NULL, '' and a value of only spaces, tabs or line breaks meant "no English
 * name" to the one reader (JavaScript fell back to the Dutch one), so they get
 * no row — which is the same answer.
 *
 * SAFE TO RUN TWICE. A row that already exists is never overwritten, and once
 * the column is gone there is nothing left to do.
 *
 * REFUSES RATHER THAN LOSES. If names could not be moved because
 * site_languages has no row for "en", the migration stops before the drop and
 * says so. That is the one case where an installation's own history would be
 * thrown away, so it is the one case that must fail loudly.
 */
final class MoveOrderItemEnglishNamesIntoTheSnapshotTable extends AbstractMigration
{
    private const LANGUAGE = 'en';

    public function up(): void
    {
        if (!$this->hasTable('site_languages')
            || !$this->hasTable('order_items')
            || !$this->hasTable('order_item_translations')) {
            return;
        }

        if (!$this->table('order_items')->hasColumn('product_name_en')) {
            return;
        }

        $this->execute(
            'INSERT INTO order_item_translations (order_item_id, language_code, product_name, created_at, updated_at)
             SELECT o.id, ' . $this->quote(self::LANGUAGE) . ', o.product_name_en,
                    COALESCE(o.created_at, NOW()), COALESCE(o.updated_at, NOW())
               FROM order_items o
              WHERE ' . $this->hasWords('o.product_name_en') . '
                AND EXISTS (SELECT 1 FROM site_languages l WHERE l.code = ' . $this->quote(self::LANGUAGE) . ')
                AND NOT EXISTS (
                    SELECT 1 FROM order_item_translations t
                     WHERE t.order_item_id = o.id AND t.language_code = ' . $this->quote(self::LANGUAGE) . '
                )
              ORDER BY o.id'
        );

        $row = $this->fetchRow(
            'SELECT COUNT(*) AS c FROM order_items o
              WHERE ' . $this->hasWords('o.product_name_en') . '
                AND NOT EXISTS (
                    SELECT 1 FROM order_item_translations t
                     WHERE t.order_item_id = o.id
                       AND t.language_code = ' . $this->quote(self::LANGUAGE) . '
                       AND t.product_name IS NOT NULL
                )'
        );

        if ((int) ($row['c'] ?? 0) > 0) {
            throw new \RuntimeException(sprintf(
                '%d order line(s) have an English product-name snapshot that could not be moved into '
                . 'order_item_translations, most likely because site_languages has no row for "%s". '
                . 'Nothing was dropped — an order\'s own history is not thrown away.',
                (int) $row['c'],
                self::LANGUAGE
            ));
        }

        $this->table('order_items')->removeColumn('product_name_en')->update();
    }

    /**
     * Forward-only (db/migrations/CLAUDE.md). The extra language versions of
     * the snapshot live in order_item_translations now.
     */
    public function down(): void
    {
    }

    /** Words are anything other than spaces, tabs, line breaks, NUL and vertical tabs (the rule of 20260918170000). */
    private function hasWords(string $column): string
    {
        $expression = "COALESCE({$column}, '')";
        foreach ([9, 10, 13, 0, 11] as $byte) {
            $expression = "REPLACE({$expression}, CHAR({$byte} USING utf8mb4), ' ')";
        }

        return "TRIM({$expression}) <> ''";
    }

    private function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
