<?php

/**
 * Renders the Marquee section (App\Service\MarqueeContent) — extracted
 * verbatim from index.php. Deliberately no `<section>`/`.container` wrapper
 * (matches the original markup exactly); assets/js/blocks/marquee.js's initMarquee()
 * only duplicates/pads these rendered items for the seamless scroll loop, it
 * does not own the content. Caller must already have checked
 * $marquee['state'] !== MarqueeContent::STATE_HIDDEN before calling this.
 *
 * Every label arrives as one LocalizedValue (App\Service\Blocks\
 * BlockLocalization), each item's own: SiteText prints the words a visitor
 * sees first and the escaped data-nl/data-en pair for the V1 switch, so this
 * file knows no language, no default and no fallback. All of it is plain
 * text.
 *
 * @param array<string, mixed> $marquee see MarqueeContent::forSection()
 */
function render_section_marquee(array $marquee): void
{
    if ($marquee['items'] === []) {
        // No items at all (every item hidden/deleted, or the row is
        // missing) — render nothing rather than an empty scrolling band,
        // the same "empty block leaves no gap" rule as the Rich text block.
        return;
    }

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $text = static fn (\App\Service\Language\LocalizedValue $value): string => \App\Service\Language\SiteText::visibleOf($value);
    $pair = static fn (\App\Service\Language\LocalizedValue $value): string => \App\Service\Language\SiteText::attrsOf($value);
    ?>
    <div class="marquee" aria-hidden="true">
      <div class="marquee__track" data-marquee-track>
        <?php foreach ($marquee['items'] as $item): ?>
        <span <?= $pair($item['label']) ?>><?= $h($text($item['label'])) ?></span>
        <?php endforeach; ?>
      </div>
    </div>
    <?php
}
