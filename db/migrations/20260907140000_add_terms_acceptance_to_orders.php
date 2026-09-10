<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Records, per order, that the customer accepted the Terms & Conditions at
 * checkout, and exactly which version of the CMS-managed Terms & Conditions
 * page (information_pages, slug "algemene-voorwaarden" — see
 * App\Service\LegalPages) they accepted, since that content is editable at
 * any time through the admin.
 *
 * `terms_content_hash` is a SHA-256 of the sanitized Terms & Conditions
 * content_html at the moment the order was created (see
 * App\Service\LegalPages::hashCurrentTerms(), called from api/checkout.php
 * before the order is created) — never a manually maintained version number.
 * Identical content always hashes identically; any edit to the CMS page
 * automatically changes the hash for orders placed after that edit, while
 * historical orders keep the hash of what the customer actually accepted.
 *
 * Purely additive, no backfill: orders placed before this migration have no
 * recorded acceptance (`terms_accepted` defaults to false, the other two
 * columns stay null), which correctly reflects that nothing was captured for
 * them at the time.
 */
final class AddTermsAcceptanceToOrders extends AbstractMigration
{
    public function change(): void
    {
        $this->table('orders')
            ->addColumn('terms_accepted', 'boolean', ['default' => false, 'after' => 'shipping_method'])
            ->addColumn('terms_accepted_at', 'datetime', ['null' => true, 'after' => 'terms_accepted'])
            ->addColumn('terms_content_hash', 'string', ['limit' => 64, 'null' => true, 'after' => 'terms_accepted_at'])
            ->update();
    }
}
