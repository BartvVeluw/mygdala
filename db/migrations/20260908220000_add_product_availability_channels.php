<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Where a product is allowed to be SOLD, as two independent switches on the
 * product itself.
 *
 * Until now `products.active` answered one question — "does this product
 * exist publicly at all" — and the answer implied exactly one destination:
 * the shop. That stopped being true the moment personalization became a
 * catalogue of its own. A blank keychain that only exists to be engraved has
 * no business in the ordinary shop grid, and must not be buyable as a blank;
 * an ordinary cutting board that also happens to offer engraving belongs in
 * both places.
 *
 * So the question splits in two, and neither column replaces `active`:
 *
 *   active                       the master switch. 0 = nowhere at all, and
 *                                the product page itself 404s. Unchanged.
 *   in_shop                      appears in the shop grid, on collection
 *                                pages and among related products, and may
 *                                be bought the ordinary way.
 *   in_personalization_catalog   appears on the public Personalisatie page.
 *
 * That gives the three real states the owner asked for: shop only
 * (1/0 — every product that exists today), personalization only (0/1) and
 * both (1/1). A product with neither is not an error state either: it is
 * reachable by its own URL and by nothing else, which is a perfectly
 * ordinary way to park something.
 *
 * ## Nothing about the catalogue changes today
 *
 * `in_shop` defaults to 1, so every existing product stays exactly where it
 * is. `in_personalization_catalog` defaults to 0 and is then backfilled to 1
 * for the products that already have personalization SWITCHED ON — those are
 * precisely the products the new page is meant to list, and listing them
 * there adds a place they appear without removing one.
 *
 * ## Purchasability is derived, never stored twice
 *
 * There is deliberately no "personalization required" copy here.
 * `product_personalization_settings.personalization_mode` already says
 * whether engraving is required, and `in_shop = 0` says the product has no
 * ordinary purchase path at all; App\Service\Personalization\ProductPersonalizationContent
 * combines the two into one answer so the frontend, the validator and the
 * checkout cannot disagree about it.
 *
 * MySQL 5.7 / PHP 8.2 compatible: plain boolean columns with defaults, no
 * generated columns, and an index that matches the query the public page
 * actually runs.
 */
final class AddProductAvailabilityChannels extends AbstractMigration
{
    public function up(): void
    {
        $this->table('products')
            ->addColumn('in_shop', 'boolean', [
                'default' => true,
                'after' => 'active',
            ])
            ->addColumn('in_personalization_catalog', 'boolean', [
                'default' => false,
                'after' => 'in_shop',
            ])
            // The public Personalisatie page's whole query is
            // "active AND in_personalization_catalog", and the shop grid's is
            // "active AND in_shop"; one composite index serves the first and
            // the leading column serves nothing useful on its own, so both
            // get their own narrow index rather than a shared prefix.
            ->addIndex(['active', 'in_shop'])
            ->addIndex(['active', 'in_personalization_catalog'])
            ->update();

        // Backfill: a product whose personalization is switched ON is exactly
        // what the new page exists to list. Only `is_enabled = 1` — a product
        // that was enrolled but never finished has nothing to show and would
        // only produce an empty-looking card.
        $this->getAdapter()->getConnection()->exec(
            'UPDATE products p
             INNER JOIN product_personalization_settings s
                ON s.product_id = p.id AND s.is_enabled = 1
             SET p.in_personalization_catalog = 1'
        );
    }

    public function down(): void
    {
        $this->table('products')
            ->removeIndex(['active', 'in_shop'])
            ->removeIndex(['active', 'in_personalization_catalog'])
            ->removeColumn('in_shop')
            ->removeColumn('in_personalization_catalog')
            ->update();
    }
}
