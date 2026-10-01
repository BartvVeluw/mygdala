<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Every gallery block on portfolio items becomes a Projecten block, in place.
 *
 * Until v0.1.15 the gallery (`item_gallery`) could show portfolio items or a
 * collection, and the block picker offered it twice: "Portfoliogalerij" under
 * Portfolio and "Collectiegalerij" under Shop. Portfolio items have a block of
 * their own since Projecten 2.0 (`project_cards`, App\Service\Blocks\ProjectCardsBlock),
 * built on the very same `item_galleries` row, content class and partial. So
 * the gallery is the Shop's Collectiegalerij now, and its portfolio instances
 * move to Projecten.
 *
 * IN PLACE: only page_sections.section_type changes. The page_sections row
 * keeps its id, page, position, visibility and Extra vormgeving; the
 * item_galleries row keeps its id and every setting (choice of projects,
 * order, maximum, filter bar, card presentation, the button and its style,
 * `tight_top`), its picked projects (item_gallery_portfolio_items) and its
 * words in every language (block_translations, owner item_galleries). Nothing
 * is copied, so nothing can be duplicated or orphaned. The settings Projecten
 * does not have — the zoom switch and the link for cards without a page —
 * never did anything for portfolio items (a project's picture always zooms,
 * its card links only to its own page) and stay stored as they were.
 *
 * Which rows: a gallery whose source is `portfolio`, and one with no source
 * at all (NULL or ''), which the gallery used to render as the portfolio grid
 * whenever the Portfolio ran; the latter gets `portfolio` written so the
 * Projecten block shows it. A gallery on a collection, or on a source nothing
 * declares, stays a gallery. A draft of a portfolio gallery
 * (content_block_drafts) becomes a draft of Projecten the same way, and the
 * site search's copy of its words (search_block_texts) names its new type.
 *
 * Independent of which modules are on: switching the Portfolio off never
 * touches the database, and this migration does not ask.
 *
 * Idempotent: a second run finds no `item_gallery` on portfolio items. A row
 * whose section_id somehow already has a Projecten section is left alone
 * rather than breaking UNIQUE(section_type, section_id). Forward-only.
 */
final class TurnPortfolioGalleriesIntoProjectsBlocks extends AbstractMigration
{
    private const PORTFOLIO = 'portfolio';

    public function up(): void
    {
        if (!$this->hasTable('item_galleries')) {
            return;
        }

        $portfolio = "(g.source_type = '" . self::PORTFOLIO . "' OR g.source_type IS NULL OR g.source_type = '')";

        foreach (['page_sections', 'content_block_drafts'] as $table) {
            if (!$this->hasTable($table)) {
                continue;
            }

            // The rows with no source first, by id, so the Projecten block
            // finds its source on them.
            $this->execute(
                "UPDATE item_galleries g
                 JOIN {$table} s ON s.section_id = g.id AND s.section_type = 'item_gallery'
                 SET g.source_type = '" . self::PORTFOLIO . "'
                 WHERE g.source_type IS NULL OR g.source_type = ''"
            );

            $this->execute(
                "UPDATE {$table} s
                 JOIN item_galleries g ON g.id = s.section_id
                 LEFT JOIN {$table} taken ON taken.section_type = 'project_cards' AND taken.section_id = s.section_id
                 SET s.section_type = 'project_cards'
                 WHERE s.section_type = 'item_gallery'
                   AND {$portfolio}
                   AND taken.id IS NULL"
            );
        }

        // The site search's copy of a block's words names the block's type
        // (search_block_texts, Search 2.0) and reads only registered types:
        // the converted blocks' words follow them, so they stay findable
        // whichever of the two modules is on.
        if ($this->hasTable('search_block_texts') && $this->hasTable('page_sections')) {
            $this->execute(
                "UPDATE search_block_texts t
                 JOIN page_sections s ON s.id = t.page_section_id
                 SET t.section_type = s.section_type
                 WHERE t.section_type = 'item_gallery' AND s.section_type = 'project_cards'"
            );
        }
    }

    public function down(): void
    {
    }
}
