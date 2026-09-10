<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Phase 4 of the content-block refactor (docs/content-blocks/ROADMAP.md):
 * the last two Portfolio-specific FIXED blocks become ONE ordinary,
 * reusable, repeatable block type with a selectable content source.
 *
 *   portfolio_gallery  the Portfolio page's filter bar + gallery grid
 *   portfolio_teaser   the homepage's "Een greep uit eerder werk" section
 *
 * Both rendered the same `.gallery-grid` > `.gallery-item` component over
 * the same portfolio items; they differed only in WHICH items (all vs the
 * homepage-featured selection), whether a filter bar and lightbox were
 * shown, and what heading/footer copy the template hardcoded around them.
 * Every one of those differences is now a field on `item_galleries`, so the
 * two become two instances of `item_gallery` — addable anywhere, repeatable,
 * and each with its own independent settings.
 *
 * The content source is an explicit, closed list (`portfolio` | `collection`,
 * see App\Service\ItemGalleryContent::SOURCES), NOT a query builder: a third
 * source later means one more branch there, not a new abstraction here.
 *
 * Content preservation. Nothing is retyped and no item row is touched:
 *   - the Portfolio instance keeps source `portfolio` / scope `all`, its
 *     filter bar and its lightbox, plus the closing paragraph the template
 *     printed under the grid (NL + EN);
 *   - the homepage instance keeps source `portfolio` / scope `featured`,
 *     no filter bar, no lightbox, the eyebrow/title/lead the template
 *     hardcoded, the "Bekijk volledige portfolio" button and the
 *     `/portfolio.php` link its non-project cards carried;
 *   - `background` + `tight_top` carry the two sections' own wrappers
 *     (`<section class="bg-soft">` and `<section style="padding-top:0;">`)
 *     forward, so neither page shifts a pixel;
 *   - the existing `page_sections` rows are REPOINTED rather than recreated,
 *     so both blocks keep their position and their is_active.
 *
 * `portfolio_galleries` stops being a page section. It has always doubled as
 * the catalogue container every `portfolio_gallery_items` row hangs off, and
 * that is all it is now: its `page_slug`/`section_key` (the hardcoded
 * "portfolio:gallery" identity) and its `is_active` — whose one job, hiding
 * the section, is now the block's own `is_active` — are dropped once the
 * flag has been copied into the migrated block. Items, categories, detail
 * pages and the homepage `is_featured` selection all stay exactly where they
 * are, managed via Portfolio.
 *
 * Idempotent and forward-only; safe on a fresh install (a missing page,
 * attachment or gallery row is simply skipped). MySQL/Vimexx-compatible:
 * CREATE TABLE plus plain INSERT/UPDATE, no CTEs and no window functions.
 */
final class TurnThePortfolioBlocksIntoOneReusableBlock extends AbstractMigration
{
    private const PORTFOLIO_PAGE = 'portfolio';

    private const HOME_PAGE = 'index';

    /** The section_key both migrated instances get, like every other phase-2/3/4 migration. */
    private const MIGRATED_SECTION_KEY = 'main';

    /**
     * The closing paragraph partials/section-portfolio-gallery.php printed
     * under the grid — theme-owned copy that has to become content now, or
     * the block would still talk about "jouw idee" wherever it is reused.
     */
    private const PORTFOLIO_FOOTER_NOTE = [
        'nl' => 'Staat jouw idee er niet tussen? Dat betekent niet dat het niet mogelijk is — ik werk graag aan bijzondere en eenmalige opdrachten.',
        'en' => "Don't see your idea here? That doesn't mean it isn't possible — I love working on special, one-off commissions.",
    ];

    /**
     * The homepage teaser's own heading and button, copied verbatim out of
     * the hardcoded partials/section-portfolio-teaser.php it replaces.
     */
    private const TEASER = [
        'eyebrow_nl' => 'Portfolio',
        'eyebrow_en' => 'Portfolio',
        'title_nl' => 'Een greep uit eerder werk',
        'title_en' => 'A glimpse of past work',
        'lead_nl' => 'Van een gegraveerde skyline van Nijmegen tot een medaille voor de Zevenheuvelenloop — elk stuk is met aandacht ontworpen.',
        'lead_en' => 'From an engraved Nijmegen skyline to a medal for the Zevenheuvelenloop — every piece is designed with care.',
        'button_label_nl' => 'Bekijk volledige portfolio',
        'button_label_en' => 'View full portfolio',
        'button_url' => '/portfolio.php',
    ];

    public function up(): void
    {
        $this->createItemGalleriesTable();

        if (InstallState::isFreshInstall($this)) {
            // Both galleries below carry Van Veluw Laserdesign's own copy
            // and point at its Portfolio page. There is nothing to migrate
            // on an install that never had them.
            // See src/Install/InstallState.php.
            return;
        }

        $this->migratePortfolioGallery();
        $this->migrateHomepageTeaser();

        $this->retireTheCatalogueSectionIdentity();
    }

    public function down(): void
    {
        if ($this->hasTable('item_galleries')) {
            $this->table('item_galleries')->drop()->save();
        }
    }

    // ---------------------------------------------------------- new schema

    private function createItemGalleriesTable(): void
    {
        if ($this->hasTable('item_galleries')) {
            return;
        }

        $this->table('item_galleries', ['id' => true])
            ->addColumn('page_slug', 'string', ['limit' => 100])
            ->addColumn('section_key', 'string', ['limit' => 100])
            // Which content this block shows. A closed vocabulary validated
            // against App\Service\ItemGalleryContent::SOURCES on every read
            // and write — never used to build a table name or class.
            ->addColumn('source_type', 'string', ['limit' => 30, 'default' => 'portfolio'])
            // source_type = 'portfolio': 'all' | 'featured' (the homepage
            // selection made per item in Portfolio).
            ->addColumn('portfolio_scope', 'string', ['limit' => 20, 'default' => 'all'])
            // source_type = 'collection': which collection's products.
            ->addColumn('collection_id', 'integer', ['signed' => false, 'null' => true])
            // NULL = show every item the source yields.
            ->addColumn('max_items', 'integer', ['null' => true])
            ->addColumn('show_filter_bar', 'boolean', ['default' => false])
            ->addColumn('enable_lightbox', 'boolean', ['default' => false])
            // Where a card WITHOUT its own detail page links to. Empty = it
            // does not link at all, which is what makes it lightbox-able.
            ->addColumn('fallback_link_url', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('eyebrow_nl', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('eyebrow_en', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('title_nl', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('title_en', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('lead_nl', 'text', ['null' => true])
            ->addColumn('lead_en', 'text', ['null' => true])
            ->addColumn('footer_note_nl', 'text', ['null' => true])
            ->addColumn('footer_note_en', 'text', ['null' => true])
            ->addColumn('button_label_nl', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('button_label_en', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('button_url', 'string', ['limit' => 255, 'null' => true])
            // 'default' | 'soft' — the two section backgrounds this theme has.
            ->addColumn('background', 'string', ['limit' => 20, 'default' => 'default'])
            // Renders the section without its top padding, so it reads as one
            // block with whatever sits above it.
            ->addColumn('tight_top', 'boolean', ['default' => false])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['page_slug', 'section_key'], ['unique' => true])
            // A deleted collection must not leave a block pointing at a row
            // that no longer exists; the block then simply has no source and
            // renders nothing until the editor picks a new one.
            ->addForeignKey('collection_id', 'collections', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE'])
            ->create();
    }

    // ------------------------------------------------------- the migration

    private function migratePortfolioGallery(): void
    {
        $galleryId = $this->insertGallery(self::PORTFOLIO_PAGE, [
            'source_type' => 'portfolio',
            'portfolio_scope' => 'all',
            'show_filter_bar' => 1,
            'enable_lightbox' => 1,
            'footer_note_nl' => self::PORTFOLIO_FOOTER_NOTE['nl'],
            'footer_note_en' => self::PORTFOLIO_FOOTER_NOTE['en'],
            'background' => 'default',
            'tight_top' => 1,
            // The old "Hele portfolio-sectie verbergen" toggle lived on the
            // catalogue row; it is this block's own visibility now.
            'is_active' => $this->catalogueIsActive() ? 1 : 0,
        ]);

        if ($galleryId !== null) {
            $this->repointAttachment(self::PORTFOLIO_PAGE, 'portfolio_gallery', $galleryId);
        }
    }

    private function migrateHomepageTeaser(): void
    {
        $galleryId = $this->insertGallery(self::HOME_PAGE, [
            'source_type' => 'portfolio',
            // The teaser has always shown the items curated for the homepage
            // ("Toon op homepage" per item), in their own order.
            'portfolio_scope' => 'featured',
            'show_filter_bar' => 0,
            'enable_lightbox' => 0,
            // Its cards without a project page linked to the Portfolio page.
            'fallback_link_url' => '/portfolio.php',
            'eyebrow_nl' => self::TEASER['eyebrow_nl'],
            'eyebrow_en' => self::TEASER['eyebrow_en'],
            'title_nl' => self::TEASER['title_nl'],
            'title_en' => self::TEASER['title_en'],
            'lead_nl' => self::TEASER['lead_nl'],
            'lead_en' => self::TEASER['lead_en'],
            'button_label_nl' => self::TEASER['button_label_nl'],
            'button_label_en' => self::TEASER['button_label_en'],
            'button_url' => self::TEASER['button_url'],
            'background' => 'soft',
            'tight_top' => 0,
            'is_active' => 1,
        ]);

        if ($galleryId !== null) {
            $this->repointAttachment(self::HOME_PAGE, 'portfolio_teaser', $galleryId);
        }
    }

    /**
     * `portfolio_galleries` is only the item catalogue from here on: the
     * hardcoded "portfolio:gallery" section identity and the section-level
     * visibility flag have both moved to the block above. Dropped only once
     * both blocks exist, so a half-finished run keeps its source data.
     */
    private function retireTheCatalogueSectionIdentity(): void
    {
        if (!$this->hasTable('portfolio_galleries')) {
            return;
        }

        foreach ([self::PORTFOLIO_PAGE, self::HOME_PAGE] as $pageSlug) {
            if ($this->galleryId($pageSlug) === null) {
                return;
            }
        }

        $table = $this->table('portfolio_galleries');

        if ($table->hasIndexByName('page_slug')) {
            $table->removeIndexByName('page_slug')->save();
        }

        foreach (['page_slug', 'section_key', 'is_active'] as $column) {
            if ($table->hasColumn($column)) {
                $table->removeColumn($column)->save();
            }
        }
    }

    // ------------------------------------------------------------- helpers

    /**
     * Inserts one item_galleries row, or returns the existing one's id when
     * this migration already ran (idempotency).
     *
     * @param array<string, int|string|null> $values
     */
    private function insertGallery(string $pageSlug, array $values): ?int
    {
        $existing = $this->galleryId($pageSlug);
        if ($existing !== null) {
            return $existing;
        }

        $columns = ['page_slug', 'section_key'];
        $literals = [$this->q($pageSlug), $this->q(self::MIGRATED_SECTION_KEY)];

        foreach ($values as $column => $value) {
            $columns[] = $column;
            $literals[] = is_int($value) ? (string) $value : $this->qOrNull($value);
        }

        $columns[] = 'created_at';
        $columns[] = 'updated_at';
        $now = $this->q(date('Y-m-d H:i:s'));
        $literals[] = $now;
        $literals[] = $now;

        $this->execute(
            'INSERT INTO item_galleries (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', $literals) . ')'
        );

        return $this->galleryId($pageSlug);
    }

    private function galleryId(string $pageSlug): ?int
    {
        if (!$this->hasTable('item_galleries')) {
            return null;
        }

        $row = $this->oneRow(
            'SELECT id FROM item_galleries WHERE page_slug = ' . $this->q($pageSlug)
            . ' AND section_key = ' . $this->q(self::MIGRATED_SECTION_KEY) . ' LIMIT 1'
        );

        return $row === null ? null : (int) $row['id'];
    }

    /**
     * Hands the page's existing attachment to the new block instead of
     * creating a second one, so position and is_active survive untouched.
     * When the old attachment is missing (a fresh install), the block is
     * appended at the bottom of that page's one list instead.
     */
    private function repointAttachment(string $pageContentKey, string $oldType, int $galleryId): void
    {
        $page = $this->oneRow(
            'SELECT id FROM pages WHERE content_key = ' . $this->q($pageContentKey) . ' LIMIT 1'
        );
        if ($page === null) {
            return;
        }

        $pageId = (int) $page['id'];

        $existing = $this->oneRow(
            'SELECT id FROM page_sections WHERE page_id = ' . $pageId
            . ' AND section_type = ' . $this->q($oldType) . ' LIMIT 1'
        );

        if ($existing === null) {
            if (!$this->attachmentExists('item_gallery', $galleryId)) {
                $this->insertAttachment($pageId, $pageContentKey, $galleryId, $this->nextSortOrder($pageId));
            }

            return;
        }

        $this->execute(
            'UPDATE page_sections SET section_type = ' . $this->q('item_gallery')
            . ', section_key = ' . $this->q(self::MIGRATED_SECTION_KEY)
            . ', section_id = ' . $galleryId
            . ', updated_at = NOW() WHERE id = ' . (int) $existing['id']
        );
    }

    private function catalogueIsActive(): bool
    {
        if (!$this->hasTable('portfolio_galleries') || !$this->table('portfolio_galleries')->hasColumn('is_active')) {
            return true;
        }

        $row = $this->oneRow('SELECT is_active FROM portfolio_galleries ORDER BY id ASC LIMIT 1');

        return $row === null ? true : (bool) $row['is_active'];
    }

    private function attachmentExists(string $sectionType, int $sectionId): bool
    {
        return $this->oneRow(
            'SELECT id FROM page_sections WHERE section_type = ' . $this->q($sectionType)
            . ' AND section_id = ' . $sectionId . ' LIMIT 1'
        ) !== null;
    }

    private function nextSortOrder(int $pageId): int
    {
        $row = $this->oneRow(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 AS next FROM page_sections WHERE page_id = ' . $pageId
        );

        return (int) $row['next'];
    }

    private function insertAttachment(int $pageId, string $pageSlug, int $sectionId, int $sortOrder): void
    {
        $now = $this->q(date('Y-m-d H:i:s'));

        $this->execute(
            'INSERT INTO page_sections
                (page_id, page_slug, section_type, section_key, section_id, sort_order, is_active, created_at, updated_at)
             VALUES ('
            . $pageId . ', ' . $this->q($pageSlug) . ', ' . $this->q('item_gallery') . ', '
            . $this->q(self::MIGRATED_SECTION_KEY) . ', ' . $sectionId . ', ' . $sortOrder . ', 1, '
            . $now . ', ' . $now . ')'
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

    private function q(string $value): string
    {
        return $this->getAdapter()->getConnection()->quote($value);
    }

    private function qOrNull(mixed $value): string
    {
        $value = $value === null ? '' : (string) $value;

        return $value === '' ? 'NULL' : $this->q($value);
    }
}
