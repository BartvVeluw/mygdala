<?php

/**
 * Renders ONE Witruimte block (App\Service\SpacerContent): room between the
 * blocks around it, and nothing else.
 *
 * Purely layout. One empty element with a height class from the closed list
 * (SpacerContent::SIZES; the heights are in assets/css/blocks/spacer.css),
 * hidden from assistive technology: no heading, no text, nothing focusable,
 * no landmark. A size this file does not know is the default, so a stray
 * value can never become a class nobody wrote.
 *
 * Caller must already have checked $content['state'] !==
 * SpacerContent::STATE_HIDDEN. STATE_FALLBACK (no row) renders nothing: a
 * spacer that is not there leaves no room.
 *
 * @param array{state: string, size: string} $content
 */
function render_section_spacer(array $content): void
{
    if ($content['state'] !== \App\Service\SpacerContent::STATE_ACTIVE) {
        return;
    }

    $size = \App\Service\SpacerContent::size($content['size']);
    ?>
  <div class="spacer spacer--<?= htmlspecialchars($size, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></div>
    <?php
}
