<?php

/**
 * Renders the Marquee section (App\Service\MarqueeContent) — extracted
 * verbatim from index.php. Deliberately no `<section>`/`.container` wrapper
 * (matches the original markup exactly); assets/js/blocks/marquee.js's initMarquee()
 * only duplicates/pads these rendered items for the seamless scroll loop, it
 * does not own the content. Caller must already have checked
 * $marquee['state'] !== MarqueeContent::STATE_HIDDEN before calling this.
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
    ?>
    <div class="marquee" aria-hidden="true">
      <div class="marquee__track" data-marquee-track>
        <?php foreach ($marquee['items'] as $item): ?>
        <span <?= \App\Service\Language\SiteText::attrs($item['label_nl'], $item['label_en']) ?>><?= $h(\App\Service\Language\SiteText::visible($item['label_nl'], $item['label_en'])) ?></span>
        <?php endforeach; ?>
      </div>
    </div>
    <?php
}
