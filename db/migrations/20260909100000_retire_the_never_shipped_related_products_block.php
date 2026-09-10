<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Retires the `related_products` page_sections rows the CMS can no longer
 * render.
 *
 * WHAT HAPPENED. On 2026-09-07 "Gerelateerde producten" was first built as an
 * ordinary content block: a `related_products` type in the section registry,
 * a `related_products_sections` table, and an editor. Before it was
 * committed the feature was rebuilt as something else entirely — the
 * automatic section on a product detail page, driven by the product's
 * collection (see App\Service\RelatedProductsContent,
 * partials/related-products.php and
 * 20260908170000_add_related_products_settings.php). The block's code and
 * its two table migrations were dropped and the tables rolled back, so
 * nothing of it survives in this repository.
 *
 * What DID survive is data. While the block existed an editor had already
 * placed one on a page through the page builder, and that page_sections row
 * was never removed: nothing rolls back a row that a person created. It
 * pointed at a `section_type` no longer registered, and
 * App\Service\SectionRegistry threw on it — one stale row, and the entire
 * public page died mid-render. The block is now skipped and logged instead
 * (SectionRegistry::renderableDefinition()), but the row still has no
 * meaning, so it is retired here.
 *
 * WHY IT WAS ONLY EVER GOING TO BITE A USER-CREATED PAGE. Removing a block
 * type is a data migration, not just a code deletion, and this one had no
 * data migration at all — not a slug-scoped one, none. The rows it left are
 * invisible to every later phase, because each of those selects on a
 * section_type it knows ('cta_band', 'contact_form', …) and this type is in
 * no such list. The page it happened to hit is simply the page an editor was
 * building at the time. See CONTENT-BLOCKS.md, "Een blok verwijderen".
 *
 * WHAT THIS DOES. Every attachment of the type, on every page, selected on
 * the retired type alone — no page slug, no id, no list of known pages, so a
 * CMS page any user created is treated exactly like a predefined one. The
 * rows are DEACTIVATED, not deleted: is_active = 0 is what the renderer and
 * the page builder already understand as "not on the page", it costs
 * nothing, and it keeps the row, its section_key and its position for
 * whoever wants to look. admin/page.php marks it "Niet-ondersteund
 * contentblok" either way.
 *
 * SAFETY. Skipped entirely when `related_products_sections` exists — a
 * database where the block's own table is still present is one this
 * migration knows nothing about, and guessing at content it cannot see is
 * exactly what it must not do. Idempotent: the UPDATE is scoped to rows that
 * are still active, so a second run matches nothing, and a row an editor has
 * since re-enabled deliberately is left alone. Sort order is untouched, so
 * every other block keeps its position.
 *
 * Forward-only: down() does not re-enable the rows. Turning an unrenderable
 * block back on is not a rollback, it is the bug again.
 *
 * MySQL/Vimexx: one UPDATE, no CTEs, no window functions.
 */
final class RetireTheNeverShippedRelatedProductsBlock extends AbstractMigration
{
    /**
     * The block type that was dropped before it shipped. A literal on
     * purpose: a migration records what was true when it was written, so it
     * must never read App\Service\Blocks\BlockDefinitions — that list keeps
     * changing, and a historical migration that follows it would do
     * something different every time it is replayed.
     */
    private const RETIRED_TYPE = 'related_products';

    /** The block's own content table, whose absence is the proof it never shipped. */
    private const RETIRED_TABLE = 'related_products_sections';

    public function up(): void
    {
        if (!$this->hasTable('page_sections') || $this->hasTable(self::RETIRED_TABLE)) {
            return;
        }

        $this->execute(
            "UPDATE page_sections
                SET is_active = 0, updated_at = NOW()
              WHERE section_type = '" . self::RETIRED_TYPE . "'
                AND is_active = 1"
        );
    }

    public function down(): void
    {
    }
}
