<?php

/**
 * Renders the page's quicknav — the row of anchor links above a long,
 * sectioned page (App\Service\SectionRegistry's `quicknav`).
 *
 * The links are DERIVED, never hardcoded: every active Detailsectie on this
 * page that carries an anchor becomes one link, in the page's own block
 * order, labelled with that section's short nav label (or its title when it
 * has none) — see App\Service\DetailSectionContent::navItemsForPage(). Until
 * phase 3 this partial listed the four material anchors literally, which is
 * exactly what made "the anchors must stay in sync with the sections below"
 * a thing a human had to remember; now adding, hiding, reordering or
 * removing a section updates the nav on its own.
 *
 * A page with no anchored sections renders no nav at all rather than an
 * empty bar.
 *
 * The links arrive derived: App\Service\Blocks\QuicknavBlock::render() asks
 * DetailSectionContent for them, so this file only renders and the block
 * library can show it with sample links.
 *
 * Each label arrives as one LocalizedValue
 * (App\Service\Blocks\BlockLocalization): SiteText prints the words a visitor
 * sees first and the escaped data-nl/data-en pair for the V1 switch, so this
 * file knows no language, no default and no fallback. A label is plain text.
 *
 * @param list<array{anchor: string, label: \App\Service\Language\LocalizedValue}> $items
 */
function render_section_quicknav(array $items): void
{
    if ($items === []) {
        return;
    }

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    ?>
  <section style="padding-top:0;">
    <div class="container">
      <nav class="quicknav" aria-label="Snel naar sectie" data-nl-aria="Snel naar sectie" data-en-aria="Jump to section">
        <?php foreach ($items as $item): ?>
        <a href="#<?= $h($item['anchor']) ?>" <?= \App\Service\Language\SiteText::attrsOf($item['label']) ?>><?= $h(\App\Service\Language\SiteText::visibleOf($item['label'])) ?></a>
        <?php endforeach; ?>
      </nav>
    </div>
  </section>
  <?php
}
