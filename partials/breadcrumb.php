<?php

/**
 * THE breadcrumb of this site — the one place that knows what a trail looks
 * like. Every public route builds an App\Service\Breadcrumbs\BreadcrumbTrail
 * and hands it here; nothing else prints a `.breadcrumb` any more.
 *
 * It replaces fifteen hand-written copies in fourteen templates, which had
 * drifted into three spellings of the homepage link, no `<nav>`, no list, a
 * separator that screen readers read out loud, and a current page that was
 * sometimes a link to itself.
 *
 * THE MARKUP is a landmark around an ordered list: `<nav>` with a translated
 * accessible name, one `<li>` per level, the current page as plain text
 * carrying aria-current, and the separator as a decorative span that is hidden
 * from the accessibility tree. The separator sits INSIDE the following item
 * because an `<ol>` may hold nothing but `<li>` elements.
 *
 * THE LANGUAGE ATTRIBUTES sit on the leaf element — the `<a>` or the `<span>`
 * — and never on the `<li>`: assets/js/core.js swaps `data-nl`/`data-en` by
 * assigning innerHTML, so a pair on the `<li>` would wipe out the link inside
 * it on the first toggle. The accessible name uses the `aria` family, which
 * that same script swaps (App\Service\Language\SiteText::attrsFor()).
 *
 * NOTHING IS RENDERED for a null trail or one that has no second level: see
 * BreadcrumbTrail::isRenderable(). An empty level is already gone by then, so
 * this file never prints a blank `<li>`.
 *
 * Styling is `.breadcrumb-bar` / `.breadcrumb` in assets/css/core.css, which
 * every public page loads. It is site chrome rather than a block's own
 * presentation, which is why it is not a block stylesheet.
 */

use App\Service\Breadcrumbs\BreadcrumbTrail;
use App\Service\Language\SiteText;

/**
 * @param bool $narrow the trail lines up with a narrow content column
 *                     (`.container--narrow`) instead of the full one — what a
 *                     blog post's header uses, and the only reason this
 *                     parameter exists.
 */
function render_breadcrumb(?BreadcrumbTrail $trail, bool $narrow = false): void
{
    if ($trail === null || !$trail->isRenderable()) {
        return;
    }

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $items = $trail->items();
    $last = count($items) - 1;
    ?>
    <nav class="breadcrumb-bar" aria-label="Kruimelpad"<?= SiteText::attrsFor('aria', 'Kruimelpad', 'Breadcrumb') ?>>
      <div class="container<?= $narrow ? ' container--narrow' : '' ?>">
        <ol class="breadcrumb">
          <?php foreach ($items as $index => $item): ?>
            <li class="breadcrumb__item">
              <?php if ($index > 0): ?><span class="breadcrumb__separator" aria-hidden="true">/</span><?php endif; ?>
              <?php if ($index < $last && $item->href !== null): ?>
                <a href="<?= $h($item->href) ?>"<?= SiteText::attrs($item->labelNl, $item->labelEn) ?>><?= $h(SiteText::visible($item->labelNl, $item->labelEn)) ?></a>
              <?php else: ?>
                <?php /* The page itself, and also a parent whose own address does
                         not answer right now — named, never linked. */ ?>
                <span<?= $index === $last ? ' class="breadcrumb__current" aria-current="page"' : '' ?><?= SiteText::attrs($item->labelNl, $item->labelEn) ?>><?= $h(SiteText::visible($item->labelNl, $item->labelEn)) ?></span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ol>
      </div>
    </nav>
    <?php
}
