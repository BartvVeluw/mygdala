<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Phase 2, last item: the Shop's closing "Zoek je iets specifieks?"
 * paragraph stops being hardcoded markup inside the product grid and becomes
 * an ordinary Rich text block.
 *
 * docs/CMS_CONTENT_AUDIT.md once proposed a dedicated CMS field for this one
 * paragraph. docs/content-blocks/PHASE-2.md asks whether a Rich text block
 * can replace that plan entirely — it can, now that Rich text has an
 * optional English body (the previous migration): the paragraph is nothing
 * but a bilingual piece of body copy at the bottom of the page, and as a
 * block the site owner can edit, reorder, hide or delete it like any other
 * text. So no per-page field is added, and none is needed.
 *
 * Content preservation: both languages are carried across verbatim, and the
 * block is attached directly after the product grid — where the paragraph
 * renders today. What does change, deliberately, is its typography: as a
 * Rich text block it renders in the same narrow reading column and body type
 * as every other Rich text block, instead of the wider `.lead` paragraph it
 * was. That is the point of making it ordinary content.
 *
 * Idempotent (guarded on the section_key already existing) and forward-only;
 * a missing Shop page or product grid is simply skipped.
 */
final class MoveTheShopNoteIntoARichTextBlock extends AbstractMigration
{
    private const SHOP_PAGE = 'shop';

    /** A stable, descriptive key — this block is migrated, not hand-added. */
    private const SECTION_KEY = 'shop-note';

    public function up(): void
    {
        if (!$this->hasTable('rich_text_sections')) {
            return;
        }

        $page = $this->oneRow(
            'SELECT id FROM pages WHERE content_key = ' . $this->sqlQuote(self::SHOP_PAGE) . ' LIMIT 1'
        );
        if ($page === null) {
            return;
        }

        $pageId = (int) $page['id'];

        $existing = $this->oneRow(
            'SELECT id FROM rich_text_sections
              WHERE page_slug = ' . $this->sqlQuote(self::SHOP_PAGE)
            . ' AND section_key = ' . $this->sqlQuote(self::SECTION_KEY) . ' LIMIT 1'
        );

        if ($existing === null) {
            // Verbatim from partials/section-product-grid.php's data-nl /
            // data-en attributes, wrapped in the <p> the Rich text editor
            // would produce for the same text.
            $nl = '<p>Zoek je iets specifieks of in grotere aantallen? Voor maatwerk en zakelijke bestellingen kun je nog steeds terecht via het offerteformulier.</p>';
            $en = '<p>Looking for something specific or in larger quantities? For custom and business orders you can still reach out through the quote form.</p>';

            $now = date('Y-m-d H:i:s');

            $this->execute(
                'INSERT INTO rich_text_sections
                    (page_slug, section_key, content_html, content_html_en, is_active, created_at, updated_at)
                 VALUES ('
                . $this->sqlQuote(self::SHOP_PAGE) . ', '
                . $this->sqlQuote(self::SECTION_KEY) . ', '
                . $this->sqlQuote($nl) . ', '
                . $this->sqlQuote($en) . ', 1, '
                . $this->sqlQuote($now) . ', ' . $this->sqlQuote($now) . ')'
            );

            $existing = $this->oneRow(
                'SELECT id FROM rich_text_sections
                  WHERE page_slug = ' . $this->sqlQuote(self::SHOP_PAGE)
                . ' AND section_key = ' . $this->sqlQuote(self::SECTION_KEY) . ' LIMIT 1'
            );
        }

        $this->attachAfterTheProductGrid($pageId, (int) $existing['id']);
    }

    public function down(): void
    {
        // Forward-only: the paragraph's content lives only here now.
    }

    private function attachAfterTheProductGrid(int $pageId, int $sectionId): void
    {
        $attached = $this->oneRow(
            "SELECT id FROM page_sections WHERE section_type = 'rich_text' AND section_id = " . $sectionId . ' LIMIT 1'
        );
        if ($attached !== null) {
            return;
        }

        $grid = $this->oneRow(
            'SELECT sort_order FROM page_sections
              WHERE page_id = ' . $pageId . " AND section_type = 'product_grid' LIMIT 1"
        );

        if ($grid === null) {
            $next = $this->oneRow(
                'SELECT COALESCE(MAX(sort_order), -1) + 1 AS next FROM page_sections WHERE page_id = ' . $pageId
            );
            $position = (int) $next['next'];
        } else {
            $position = (int) $grid['sort_order'] + 1;

            $this->execute(
                'UPDATE page_sections SET sort_order = sort_order + 1, updated_at = NOW()
                  WHERE page_id = ' . $pageId . ' AND sort_order >= ' . $position
            );
        }

        $now = date('Y-m-d H:i:s');

        $this->execute(
            'INSERT INTO page_sections
                (page_id, page_slug, section_type, section_key, section_id, sort_order, is_active, created_at, updated_at)
             VALUES ('
            . $pageId . ', '
            . $this->sqlQuote(self::SHOP_PAGE) . ", 'rich_text', "
            . $this->sqlQuote(self::SECTION_KEY) . ', '
            . $sectionId . ', ' . $position . ', 1, '
            . $this->sqlQuote($now) . ', ' . $this->sqlQuote($now) . ')'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function oneRow(string $sql): ?array
    {
        $row = $this->query($sql)->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function sqlQuote(string $value): string
    {
        return $this->getAdapter()->getConnection()->quote($value);
    }
}
