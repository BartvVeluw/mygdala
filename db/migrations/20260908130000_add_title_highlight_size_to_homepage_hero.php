<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Extends `homepage_hero` (see 20260905120000_create_homepage_hero_table.php
 * and 20260906040000_add_media_layout_to_homepage_hero.php) with a single
 * knob for how large the title highlight renders relative to the headline
 * itself. Purely additive and backwards-compatible: one new column with a
 * default of 100 (= exactly today's rendering, the highlight at the same
 * size as the rest of the H1), which MySQL fills in on the existing row as
 * part of the ADD COLUMN itself — no separate UPDATE needed and no existing
 * column touched.
 *
 * - `title_highlight_size`: a PERCENTAGE of the headline's own responsive
 *   font size, not an absolute size. The frontend renders it as
 *   `--hero-highlight-size: <n>%` on the H1 and `.hero h1 em` resolves it
 *   with `font-size: var(--hero-highlight-size, 100%)`, so the highlight
 *   keeps scaling with the existing `clamp()`-based responsive headline on
 *   every breakpoint instead of being pinned to a fixed pixel size. Stored
 *   as a small unsigned integer because that is all the CMS can produce:
 *   App\Service\HomepageHeroContent::HIGHLIGHT_SIZE_MIN/MAX bound the admin
 *   slider and the save-time validation, and clampHighlightSize() clamps
 *   anything else (a legacy NULL, a hand-edited row) back into range at
 *   render time.
 *
 * NULL is deliberately allowed even though the default is 100: an
 * `is_active`-style "never set" row must render exactly as it did before
 * this migration, and the Content layer already treats NULL as
 * HIGHLIGHT_SIZE_DEFAULT.
 */
final class AddTitleHighlightSizeToHomepageHero extends AbstractMigration
{
    public function up(): void
    {
        $this->table('homepage_hero')
            ->addColumn('title_highlight_size', 'smallinteger', [
                'signed' => false,
                'default' => 100,
                'null' => true,
                'after' => 'title_highlight_en',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('homepage_hero')
            ->removeColumn('title_highlight_size')
            ->update();
    }
}
