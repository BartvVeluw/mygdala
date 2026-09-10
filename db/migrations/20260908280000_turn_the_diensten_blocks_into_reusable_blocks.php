<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Phase 3 of the content-block refactor (docs/content-blocks/ROADMAP.md):
 * the last two FIXED blocks that were "managed via Diensten" become two
 * ordinary, reusable, repeatable block types.
 *
 *   detail_sections   the four `.service-detail` sections on Diensten
 *                     (Hout, Metaal, Acryl & glas, Zakelijk) stop being one
 *                     `service_details` block rendering a closed, code-defined
 *                     set of four material keys, and become FOUR independent
 *                     instances of one generic "Detailsectie" block type,
 *                     addressed like every other repeater on
 *                     (page_slug, section_key). Phase 3 additionally gives the
 *                     type rich content (replacing the plain-text
 *                     `service_paragraphs` child table), a main image with alt
 *                     text, and an image position (left/right).
 *   card_carousels    the homepage `services_carousel`, which filled itself
 *                     automatically from those same four services, becomes a
 *                     manually managed "Kaarten-carrousel" whose cards are
 *                     rows an editor curates — any number of them, on any
 *                     page the registry allows.
 *
 * Content preservation. Nothing is retyped: every title, lead, closing note,
 * CTA, "kenmerk", gallery image, teaser text, teaser image and teaser tag is
 * copied out of the `services*` tables (NL and EN), in its existing order.
 * The four paragraph lists become the new `content_html` as one escaped
 * `<p>` per paragraph, which is byte-for-byte the text the template used to
 * print. The Diensten page's existing `service_details` attachment is
 * repointed at the FIRST detail section and the other three are inserted
 * directly behind it, so the four sections keep rendering in exactly the
 * order and at exactly the position they do today; likewise the homepage's
 * existing `services_carousel` attachment is repointed at the new carousel
 * rather than recreated, so its position and is_active survive.
 *
 * Two identity fields carry today's behaviour forward:
 *   `anchor`     seeded with the old service_key, so `/diensten.php#hout`
 *                and the three footer "Materialen" links keep resolving;
 *   `nav_label`  seeded with the quicknav's own short labels ("Hout",
 *                "Acryl & glas", ...), so the quicknav — which now derives
 *                its links from the page's detail sections instead of a
 *                hardcoded list — renders the same four links it always did.
 *
 * `service_quicknav` is renamed to `quicknav` in page_sections for the same
 * reason: the block no longer knows anything about services.
 *
 * The `services`, `service_paragraphs`, `service_points`, `service_images`
 * and `service_teaser_tags` tables are dropped at the very end, once their
 * content has been copied and verified — their PHP (ServiceContent,
 * ServiceRepository, admin/service-detail.php and its API endpoints) is
 * removed in the same commit, so leaving them would be dead schema.
 *
 * Idempotent and forward-only; safe on a fresh install (a missing page or
 * attachment is simply skipped). MySQL/Vimexx-compatible: CREATE TABLE plus
 * plain INSERT/UPDATE, no CTEs and no window functions.
 */
final class TurnTheDienstenBlocksIntoReusableBlocks extends AbstractMigration
{
    private const DIENSTEN_PAGE = 'diensten';

    private const HOME_PAGE = 'index';

    /** The section_key the migrated carousel gets, like every other phase-2/3 migration. */
    private const MIGRATED_SECTION_KEY = 'main';

    /**
     * The four material keys in their fixed page order, with the short label
     * the (previously hardcoded) quicknav rendered for each. The key doubles
     * as the new section_key AND as the new anchor, which is what keeps
     * every existing `#hout`-style link working.
     */
    private const SERVICES = [
        'hout' => ['nav_nl' => 'Hout', 'nav_en' => 'Wood'],
        'metaal' => ['nav_nl' => 'Metaal', 'nav_en' => 'Metal'],
        'acryl-glas' => ['nav_nl' => 'Acryl & glas', 'nav_en' => 'Acrylic & glass'],
        'zakelijk' => ['nav_nl' => 'Zakelijk', 'nav_en' => 'Business'],
    ];

    /**
     * The homepage carousel's own heading, copied verbatim out of the
     * hardcoded partials/section-services-carousel.php it replaces. It was
     * never CMS content before; it has to become content now, or the block
     * would still say "Vier materialen" wherever else it is reused.
     */
    private const CAROUSEL_HEAD = [
        'eyebrow_nl' => 'Wat ik graveer',
        'eyebrow_en' => 'What I engrave',
        'title_nl' => 'Vier materialen, eindeloos veel mogelijkheden',
        'title_en' => 'Four materials, endless possibilities',
        'lead_nl' => 'Van een gegraveerde snijplank tot een aluminium visitekaartje voor je bedrijf: elk materiaal vraagt om een andere aanpak, techniek en afwerking.',
        'lead_en' => 'From an engraved cutting board to an aluminium business card for your company: every material calls for its own approach, technique and finish.',
    ];

    /**
     * The carousel card's "Meer over ..." button text — fixed, theme-owned
     * microcopy in App\Service\ServiceContent (TEASER_CTA_LABELS) that
     * becomes ordinary, editable card content here. The URL is the anchor
     * link the card already pointed at.
     */
    private const TEASER_CTA_LABELS = [
        'hout' => ['nl' => 'Meer over hout graveren', 'en' => 'More about wood engraving'],
        'metaal' => ['nl' => 'Meer over metaal graveren', 'en' => 'More about metal engraving'],
        'acryl-glas' => ['nl' => 'Meer over acryl & glas', 'en' => 'More about acrylic & glass'],
        'zakelijk' => ['nl' => 'Meer over zakelijk', 'en' => 'More about business work'],
    ];

    public function up(): void
    {
        $this->createDetailSectionTables();
        $this->createCardCarouselTables();

        // The carousel's own heading is Van Veluw Laserdesign's ("Vier
        // materialen, eindeloos veel mogelijkheden") and its cards are that
        // site's four materials. A fresh install has no `services` rows to
        // carry across and must not be given the heading either.
        // See src/Install/InstallState.php.
        if ($this->hasTable('services') && !InstallState::isFreshInstall($this)) {
            $this->migrateServicesIntoDetailSections();
            $this->migrateTeasersIntoOneCardCarousel();
        }

        $this->rewireDienstenPage();
        $this->rewireHomepageCarousel();
        $this->renameQuicknavAttachment();

        $this->dropTheServiceTables();
    }

    public function down(): void
    {
        foreach (['carousel_card_tags', 'carousel_cards', 'card_carousels', 'detail_section_images', 'detail_section_points', 'detail_sections'] as $table) {
            if ($this->hasTable($table)) {
                $this->table($table)->drop()->save();
            }
        }
    }

    // ---------------------------------------------------------- new schema

    private function createDetailSectionTables(): void
    {
        if (!$this->hasTable('detail_sections')) {
            $this->table('detail_sections', ['id' => true])
                ->addColumn('page_slug', 'string', ['limit' => 100])
                ->addColumn('section_key', 'string', ['limit' => 100])
                // Empty = this section is not linkable; a filled anchor is
                // what puts it in the page's quicknav and what makes
                // `#<anchor>` links to it resolve.
                ->addColumn('anchor', 'string', ['limit' => 100, 'null' => true])
                ->addColumn('nav_label_nl', 'string', ['limit' => 100, 'null' => true])
                ->addColumn('nav_label_en', 'string', ['limit' => 100, 'null' => true])
                ->addColumn('title_nl', 'string', ['limit' => 255])
                ->addColumn('title_en', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('lead_nl', 'string', ['limit' => 500, 'null' => true])
                ->addColumn('lead_en', 'string', ['limit' => 500, 'null' => true])
                ->addColumn('content_html', 'text', ['null' => true])
                ->addColumn('content_html_en', 'text', ['null' => true])
                ->addColumn('main_image_path', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('main_image_alt_nl', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('main_image_alt_en', 'string', ['limit' => 255, 'null' => true])
                // 'image_left' | 'image_right' — the same two-value vocabulary
                // App\Service\TextImageSplitContent already uses, so both
                // block types mean the same thing by "image position".
                ->addColumn('image_position', 'string', ['limit' => 20, 'default' => 'image_right'])
                ->addColumn('closing_note_nl', 'text', ['null' => true])
                ->addColumn('closing_note_en', 'text', ['null' => true])
                ->addColumn('cta_label_nl', 'string', ['limit' => 150, 'null' => true])
                ->addColumn('cta_label_en', 'string', ['limit' => 150, 'null' => true])
                ->addColumn('cta_url', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('is_active', 'boolean', ['default' => true])
                ->addColumn('created_at', 'datetime', ['null' => true])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->addIndex(['page_slug', 'section_key'], ['unique' => true])
                ->create();
        }

        if (!$this->hasTable('detail_section_points')) {
            $this->table('detail_section_points', ['id' => true])
                ->addColumn('section_id', 'integer', ['signed' => false])
                ->addColumn('title_nl', 'string', ['limit' => 255])
                ->addColumn('title_en', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('body_nl', 'text')
                ->addColumn('body_en', 'text', ['null' => true])
                ->addColumn('sort_order', 'integer', ['default' => 0])
                ->addColumn('is_active', 'boolean', ['default' => true])
                ->addColumn('created_at', 'datetime', ['null' => true])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->addForeignKey('section_id', 'detail_sections', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
                ->addIndex(['section_id'])
                ->create();
        }

        if (!$this->hasTable('detail_section_images')) {
            $this->table('detail_section_images', ['id' => true])
                ->addColumn('section_id', 'integer', ['signed' => false])
                ->addColumn('image_path', 'string', ['limit' => 255])
                ->addColumn('alt_nl', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('alt_en', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('sort_order', 'integer', ['default' => 0])
                ->addColumn('created_at', 'datetime', ['null' => true])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->addForeignKey('section_id', 'detail_sections', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
                ->addIndex(['section_id'])
                ->create();
        }
    }

    private function createCardCarouselTables(): void
    {
        if (!$this->hasTable('card_carousels')) {
            $this->table('card_carousels', ['id' => true])
                ->addColumn('page_slug', 'string', ['limit' => 100])
                ->addColumn('section_key', 'string', ['limit' => 100])
                ->addColumn('eyebrow_nl', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('eyebrow_en', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('title_nl', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('title_en', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('lead_nl', 'text', ['null' => true])
                ->addColumn('lead_en', 'text', ['null' => true])
                ->addColumn('is_active', 'boolean', ['default' => true])
                ->addColumn('created_at', 'datetime', ['null' => true])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->addIndex(['page_slug', 'section_key'], ['unique' => true])
                ->create();
        }

        if (!$this->hasTable('carousel_cards')) {
            $this->table('carousel_cards', ['id' => true])
                ->addColumn('carousel_id', 'integer', ['signed' => false])
                ->addColumn('title_nl', 'string', ['limit' => 255])
                ->addColumn('title_en', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('body_nl', 'text', ['null' => true])
                ->addColumn('body_en', 'text', ['null' => true])
                // NULL = this card renders the theme's fixed icon instead of
                // a photo, exactly what an empty teaser_image_path meant.
                ->addColumn('image_path', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('image_alt_nl', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('image_alt_en', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('link_url', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('link_label_nl', 'string', ['limit' => 150, 'null' => true])
                ->addColumn('link_label_en', 'string', ['limit' => 150, 'null' => true])
                ->addColumn('sort_order', 'integer', ['default' => 0])
                ->addColumn('is_active', 'boolean', ['default' => true])
                ->addColumn('created_at', 'datetime', ['null' => true])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->addForeignKey('carousel_id', 'card_carousels', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
                ->addIndex(['carousel_id'])
                ->create();
        }

        if (!$this->hasTable('carousel_card_tags')) {
            $this->table('carousel_card_tags', ['id' => true])
                ->addColumn('card_id', 'integer', ['signed' => false])
                ->addColumn('label_nl', 'string', ['limit' => 60])
                ->addColumn('label_en', 'string', ['limit' => 60, 'null' => true])
                ->addColumn('sort_order', 'integer', ['default' => 0])
                ->addColumn('created_at', 'datetime', ['null' => true])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->addForeignKey('card_id', 'carousel_cards', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
                ->addIndex(['card_id'])
                ->create();
        }
    }

    // ------------------------------------------------------- content moves

    private function migrateServicesIntoDetailSections(): void
    {
        $now = date('Y-m-d H:i:s');

        foreach ($this->orderedServices() as $service) {
            $serviceKey = (string) $service['service_key'];
            $nav = self::SERVICES[$serviceKey] ?? ['nav_nl' => (string) $service['title_nl'], 'nav_en' => ''];

            if ($this->detailSectionId($serviceKey) !== null) {
                continue;
            }

            [$contentHtml, $contentHtmlEn] = $this->paragraphsAsHtml((int) $service['id']);

            $this->execute(
                'INSERT INTO detail_sections
                    (page_slug, section_key, anchor, nav_label_nl, nav_label_en,
                     title_nl, title_en, lead_nl, lead_en, content_html, content_html_en,
                     main_image_path, main_image_alt_nl, main_image_alt_en, image_position,
                     closing_note_nl, closing_note_en, cta_label_nl, cta_label_en, cta_url,
                     is_active, created_at, updated_at)
                 VALUES ('
                . $this->q(self::DIENSTEN_PAGE) . ', '
                . $this->q($serviceKey) . ', '
                . $this->q($serviceKey) . ', '
                . $this->q($nav['nav_nl']) . ', '
                . $this->q($nav['nav_en']) . ', '
                . $this->q((string) $service['title_nl']) . ', '
                . $this->qOrNull($service['title_en']) . ', '
                . $this->qOrNull($service['lead_nl']) . ', '
                . $this->qOrNull($service['lead_en']) . ', '
                . $this->qOrNull($contentHtml) . ', '
                . $this->qOrNull($contentHtmlEn) . ', '
                // No material section has ever had a main image — that is
                // the capability phase 3 adds, not content it migrates.
                . 'NULL, NULL, NULL, ' . $this->q('image_right') . ', '
                . $this->qOrNull($service['closing_note_nl']) . ', '
                . $this->qOrNull($service['closing_note_en']) . ', '
                . $this->qOrNull($service['cta_label_nl']) . ', '
                . $this->qOrNull($service['cta_label_en']) . ', '
                . $this->qOrNull($service['cta_url']) . ', '
                . ((int) $service['is_active'] === 1 ? '1' : '0') . ', '
                . $this->q($now) . ', ' . $this->q($now) . ')'
            );

            $sectionId = (int) $this->detailSectionId($serviceKey);

            $this->copyPoints((int) $service['id'], $sectionId);
            $this->copyGalleryImages((int) $service['id'], $sectionId);
        }
    }

    /**
     * The four services' plain-text paragraph rows, rendered as the escaped
     * `<p>` sequence the template used to print one paragraph at a time —
     * the exact same visible text, now inside one rich-text field. Returns
     * [NL html, EN html]; the EN html is '' when no paragraph had its own
     * English text (the "leeg = zelfde als NL" rule then applies as before).
     *
     * @return array{0: string, 1: string}
     */
    private function paragraphsAsHtml(int $serviceId): array
    {
        if (!$this->hasTable('service_paragraphs')) {
            return ['', ''];
        }

        $rows = $this->query(
            'SELECT content_nl, content_en FROM service_paragraphs
              WHERE service_id = ' . $serviceId . ' ORDER BY sort_order ASC, id ASC'
        )->fetchAll(\PDO::FETCH_ASSOC);

        $nl = '';
        $en = '';
        $hasEnglish = false;

        foreach ($rows as $row) {
            $contentNl = (string) $row['content_nl'];
            $contentEn = (string) ($row['content_en'] ?? '');

            $nl .= '<p>' . htmlspecialchars($contentNl, ENT_QUOTES, 'UTF-8') . '</p>';

            if ($contentEn !== '') {
                $hasEnglish = true;
            }
            $en .= '<p>' . htmlspecialchars($contentEn !== '' ? $contentEn : $contentNl, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        return [$nl, $hasEnglish ? $en : ''];
    }

    private function copyPoints(int $serviceId, int $sectionId): void
    {
        if (!$this->hasTable('service_points')) {
            return;
        }

        $rows = $this->query(
            'SELECT * FROM service_points WHERE service_id = ' . $serviceId . ' ORDER BY sort_order ASC, id ASC'
        )->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $this->execute(
                'INSERT INTO detail_section_points
                    (section_id, title_nl, title_en, body_nl, body_en, sort_order, is_active, created_at, updated_at)
                 VALUES ('
                . $sectionId . ', '
                . $this->q((string) $row['title_nl']) . ', '
                . $this->qOrNull($row['title_en']) . ', '
                . $this->q((string) $row['body_nl']) . ', '
                . $this->qOrNull($row['body_en']) . ', '
                . (int) $row['sort_order'] . ', '
                . ((int) $row['is_active'] === 1 ? '1' : '0') . ', '
                . $this->q((string) $row['created_at']) . ', ' . $this->q((string) $row['updated_at']) . ')'
            );
        }
    }

    private function copyGalleryImages(int $serviceId, int $sectionId): void
    {
        if (!$this->hasTable('service_images')) {
            return;
        }

        $rows = $this->query(
            'SELECT * FROM service_images WHERE service_id = ' . $serviceId . ' ORDER BY sort_order ASC, id ASC'
        )->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $this->execute(
                'INSERT INTO detail_section_images
                    (section_id, image_path, alt_nl, alt_en, sort_order, created_at, updated_at)
                 VALUES ('
                . $sectionId . ', '
                . $this->q((string) $row['image_path']) . ', '
                . $this->qOrNull($row['alt_nl']) . ', '
                . $this->qOrNull($row['alt_en']) . ', '
                . (int) $row['sort_order'] . ', '
                . $this->q((string) $row['created_at']) . ', ' . $this->q((string) $row['updated_at']) . ')'
            );
        }
    }

    private function migrateTeasersIntoOneCardCarousel(): void
    {
        if ($this->carouselId() !== null) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $this->execute(
            'INSERT INTO card_carousels
                (page_slug, section_key, eyebrow_nl, eyebrow_en, title_nl, title_en, lead_nl, lead_en,
                 is_active, created_at, updated_at)
             VALUES ('
            . $this->q(self::HOME_PAGE) . ', '
            . $this->q(self::MIGRATED_SECTION_KEY) . ', '
            . $this->q(self::CAROUSEL_HEAD['eyebrow_nl']) . ', '
            . $this->q(self::CAROUSEL_HEAD['eyebrow_en']) . ', '
            . $this->q(self::CAROUSEL_HEAD['title_nl']) . ', '
            . $this->q(self::CAROUSEL_HEAD['title_en']) . ', '
            . $this->q(self::CAROUSEL_HEAD['lead_nl']) . ', '
            . $this->q(self::CAROUSEL_HEAD['lead_en']) . ', '
            . '1, ' . $this->q($now) . ', ' . $this->q($now) . ')'
        );

        $carouselId = (int) $this->carouselId();
        $sortOrder = 0;

        foreach ($this->orderedServices() as $service) {
            $serviceKey = (string) $service['service_key'];
            $cta = self::TEASER_CTA_LABELS[$serviceKey] ?? null;

            $this->execute(
                'INSERT INTO carousel_cards
                    (carousel_id, title_nl, title_en, body_nl, body_en, image_path,
                     image_alt_nl, image_alt_en, link_url, link_label_nl, link_label_en,
                     sort_order, is_active, created_at, updated_at)
                 VALUES ('
                . $carouselId . ', '
                . $this->q((string) $service['title_nl']) . ', '
                . $this->qOrNull($service['title_en']) . ', '
                . $this->qOrNull($service['teaser_body_nl']) . ', '
                . $this->qOrNull($service['teaser_body_en']) . ', '
                . $this->qOrNull($service['teaser_image_path']) . ', '
                . $this->qOrNull($service['teaser_image_alt_nl']) . ', '
                . $this->qOrNull($service['teaser_image_alt_en']) . ', '
                . $this->q('diensten.php#' . $serviceKey) . ', '
                . ($cta === null ? 'NULL' : $this->q($cta['nl'])) . ', '
                . ($cta === null ? 'NULL' : $this->q($cta['en'])) . ', '
                . $sortOrder . ', '
                // A hidden service used to be dropped from the carousel;
                // a hidden card is dropped the same way.
                . ((int) $service['is_active'] === 1 ? '1' : '0') . ', '
                . $this->q($now) . ', ' . $this->q($now) . ')'
            );

            $cardId = (int) $this->getAdapter()->getConnection()->lastInsertId();
            $this->copyTeaserTags((int) $service['id'], $cardId);

            $sortOrder++;
        }
    }

    private function copyTeaserTags(int $serviceId, int $cardId): void
    {
        if (!$this->hasTable('service_teaser_tags')) {
            return;
        }

        $rows = $this->query(
            'SELECT * FROM service_teaser_tags WHERE service_id = ' . $serviceId . ' ORDER BY sort_order ASC, id ASC'
        )->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $this->execute(
                'INSERT INTO carousel_card_tags (card_id, label_nl, label_en, sort_order, created_at, updated_at)
                 VALUES ('
                . $cardId . ', '
                . $this->q((string) $row['label_nl']) . ', '
                . $this->qOrNull($row['label_en']) . ', '
                . (int) $row['sort_order'] . ', '
                . $this->q((string) $row['created_at']) . ', ' . $this->q((string) $row['updated_at']) . ')'
            );
        }
    }

    // --------------------------------------------------- page_sections wiring

    /**
     * Replaces the Diensten page's single `service_details` attachment with
     * one `detail_section` attachment per material, at the same position and
     * in the same order. The first one REUSES the existing row (so its id,
     * position and is_active survive); the rest are inserted directly behind
     * it, shifting everything below down.
     */
    private function rewireDienstenPage(): void
    {
        $page = $this->oneRow(
            'SELECT id FROM pages WHERE content_key = ' . $this->q(self::DIENSTEN_PAGE) . ' LIMIT 1'
        );
        if ($page === null) {
            return;
        }

        $pageId = (int) $page['id'];

        $keys = [];
        foreach (array_keys(self::SERVICES) as $serviceKey) {
            $sectionId = $this->detailSectionId($serviceKey);
            if ($sectionId !== null) {
                $keys[$serviceKey] = $sectionId;
            }
        }

        if ($keys === []) {
            return;
        }

        $existing = $this->oneRow(
            'SELECT id, sort_order FROM page_sections
              WHERE page_id = ' . $pageId . " AND section_type = 'service_details' LIMIT 1"
        );

        $firstKey = (string) array_key_first($keys);

        if ($existing !== null) {
            $position = (int) $existing['sort_order'];

            // Make room for the extra sections before inserting them.
            $extra = count($keys) - 1;
            if ($extra > 0) {
                $this->execute(
                    'UPDATE page_sections SET sort_order = sort_order + ' . $extra . ', updated_at = NOW()
                      WHERE page_id = ' . $pageId . ' AND sort_order > ' . $position
                );
            }

            $this->execute(
                'UPDATE page_sections SET section_type = ' . $this->q('detail_section')
                . ', section_key = ' . $this->q($firstKey)
                . ', section_id = ' . $keys[$firstKey]
                . ', updated_at = NOW() WHERE id = ' . (int) $existing['id']
            );
        } else {
            if ($this->attachmentExists('detail_section', $keys[$firstKey])) {
                return;
            }
            $position = $this->nextSortOrder($pageId);
            $this->insertAttachment($pageId, self::DIENSTEN_PAGE, 'detail_section', $firstKey, $keys[$firstKey], $position);
        }

        $offset = 1;
        foreach ($keys as $serviceKey => $sectionId) {
            if ($serviceKey === $firstKey) {
                continue;
            }
            if (!$this->attachmentExists('detail_section', $sectionId)) {
                $this->insertAttachment($pageId, self::DIENSTEN_PAGE, 'detail_section', $serviceKey, $sectionId, $position + $offset);
            }
            $offset++;
        }
    }

    /**
     * Repoints the homepage's existing fixed `services_carousel` attachment
     * (section_id = 0) at the new, editor-managed carousel — keeping its id,
     * its position between the marquee and the werkwijze, and its is_active.
     */
    private function rewireHomepageCarousel(): void
    {
        $carouselId = $this->carouselId();
        if ($carouselId === null) {
            return;
        }

        $page = $this->oneRow(
            'SELECT id FROM pages WHERE content_key = ' . $this->q(self::HOME_PAGE) . ' LIMIT 1'
        );
        if ($page === null) {
            return;
        }

        $pageId = (int) $page['id'];

        $existing = $this->oneRow(
            'SELECT id FROM page_sections
              WHERE page_id = ' . $pageId . " AND section_type = 'services_carousel' LIMIT 1"
        );

        if ($existing === null) {
            if (!$this->attachmentExists('card_carousel', $carouselId)) {
                $this->insertAttachment($pageId, self::HOME_PAGE, 'card_carousel', self::MIGRATED_SECTION_KEY, $carouselId, $this->nextSortOrder($pageId));
            }

            return;
        }

        $this->execute(
            'UPDATE page_sections SET section_type = ' . $this->q('card_carousel')
            . ', section_key = ' . $this->q(self::MIGRATED_SECTION_KEY)
            . ', section_id = ' . $carouselId
            . ', updated_at = NOW() WHERE id = ' . (int) $existing['id']
        );
    }

    /**
     * The quicknav no longer knows anything about "services": it now derives
     * its links from whichever detail sections on its page carry an anchor.
     * Its type key follows.
     */
    private function renameQuicknavAttachment(): void
    {
        $this->execute(
            "UPDATE page_sections SET section_type = 'quicknav', updated_at = NOW()
              WHERE section_type = 'service_quicknav'"
        );
    }

    // ------------------------------------------------------------- cleanup

    /**
     * Only ever runs once every service row has a detail section to show for
     * it — a partially-copied state must keep its source data.
     */
    private function dropTheServiceTables(): void
    {
        if (!$this->hasTable('services')) {
            return;
        }

        $remaining = 0;
        foreach ($this->orderedServices() as $service) {
            if ($this->detailSectionId((string) $service['service_key']) === null) {
                $remaining++;
            }
        }

        if ($remaining > 0) {
            return;
        }

        foreach (['service_teaser_tags', 'service_images', 'service_points', 'service_paragraphs', 'services'] as $table) {
            if ($this->hasTable($table)) {
                $this->table($table)->drop()->save();
            }
        }
    }

    // ------------------------------------------------------------- helpers

    /**
     * @return list<array<string, mixed>> the services in their fixed page order
     */
    private function orderedServices(): array
    {
        if (!$this->hasTable('services')) {
            return [];
        }

        $order = [];
        foreach (array_keys(self::SERVICES) as $serviceKey) {
            $order[] = $this->q($serviceKey);
        }

        return $this->query(
            'SELECT * FROM services ORDER BY FIELD(service_key, ' . implode(', ', $order) . '), id ASC'
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function detailSectionId(string $sectionKey): ?int
    {
        $row = $this->oneRow(
            'SELECT id FROM detail_sections
              WHERE page_slug = ' . $this->q(self::DIENSTEN_PAGE)
            . ' AND section_key = ' . $this->q($sectionKey) . ' LIMIT 1'
        );

        return $row === null ? null : (int) $row['id'];
    }

    private function carouselId(): ?int
    {
        if (!$this->hasTable('card_carousels')) {
            return null;
        }

        $row = $this->oneRow(
            'SELECT id FROM card_carousels
              WHERE page_slug = ' . $this->q(self::HOME_PAGE)
            . ' AND section_key = ' . $this->q(self::MIGRATED_SECTION_KEY) . ' LIMIT 1'
        );

        return $row === null ? null : (int) $row['id'];
    }

    private function attachmentExists(string $sectionType, int $sectionId): bool
    {
        return $this->oneRow(
            'SELECT id FROM page_sections
              WHERE section_type = ' . $this->q($sectionType) . ' AND section_id = ' . $sectionId . ' LIMIT 1'
        ) !== null;
    }

    private function nextSortOrder(int $pageId): int
    {
        $row = $this->oneRow(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 AS next FROM page_sections WHERE page_id = ' . $pageId
        );

        return (int) $row['next'];
    }

    private function insertAttachment(int $pageId, string $pageSlug, string $sectionType, string $sectionKey, int $sectionId, int $sortOrder): void
    {
        $now = date('Y-m-d H:i:s');

        $this->execute(
            'INSERT INTO page_sections
                (page_id, page_slug, section_type, section_key, section_id, sort_order, is_active, created_at, updated_at)
             VALUES ('
            . $pageId . ', '
            . $this->q($pageSlug) . ', '
            . $this->q($sectionType) . ', '
            . $this->q($sectionKey) . ', '
            . $sectionId . ', ' . $sortOrder . ', 1, '
            . $this->q($now) . ', ' . $this->q($now) . ')'
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
