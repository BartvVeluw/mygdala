<?php

declare(strict_types=1);

/**
 * The menu list of the site header: `<ul class="main-nav__list">` with up to
 * three levels (App\Service\NavigationService::buildTree(),
 * NavigationRepository::MAX_DEPTH). Included by partials/header.php only; a
 * file of its own so the markup can be rendered from a synthetic tree in
 * Tests\Service\MainNavMarkupTest, without a database.
 *
 * Expects from the including scope:
 *
 *   $navItems     list of tree items (label, href, open_in_new_tab, rel,
 *                 route_key, children)
 *   $activeNav    ?string route key of the current template
 *   $requestPath  string path of the current request
 *
 * LINK AND TOGGLE ARE TWO CONTROLS. An item with a submenu renders its own
 * link exactly like an item without one, plus a separate
 * `<button type="button" class="main-nav__toggle">` next to it that only
 * opens and closes the submenu (aria-expanded, aria-controls). The link is
 * never intercepted: clicking or tapping it navigates. Only a heading
 * without a destination (link_type 'none', allowed on the top level only)
 * has no link, and it is not turned into a fake one: its words sit inside
 * the toggle, which is then the one control of that item — as it always was.
 *
 * THE STATE lives in assets/js/core.js: `.is-open` on the item plus the
 * toggle's aria-expanded, set together, whatever opened it (hover, click,
 * keyboard). The chevron in assets/css/core.css reads `.is-open` only, so
 * the arrow can never disagree with the submenu. Without JavaScript the
 * desktop menu still opens on :hover and :focus-within (core.css,
 * `.main-nav:not(.is-enhanced)`), and every link works anyway.
 *
 * NOT HERE: resolving links or labels (NavigationService), the buttons and
 * the language switch (partials/header.php).
 */

use App\Service\Language\SiteText;
use App\Service\NavigationService;

$mainNavH = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

/**
 * One level of the menu. $level is 1 for the top level; submenu panels carry
 * their level in a modifier class so the CSS can tell a dropdown (level 2)
 * from a flyout beside it (level 3).
 *
 * @param list<array<string, mixed>> $items
 */
$renderMainNavLevel = static function (array $items, int $level) use (&$renderMainNavLevel, $mainNavH, $activeNav, $requestPath): void {
    foreach ($items as $item) {
        $href = $item['href'];
        $children = $level < \App\Repository\NavigationRepository::MAX_DEPTH ? $item['children'] : [];
        if ($href === null && $children === []) {
            continue;
        }

        // Only the top level is ever marked as the current page; a submenu
        // item never was (HEADER-FOOTER.md, "De actieve link").
        $isActive = $level === 1 && $href !== null && NavigationService::isCurrent($item, $activeNav, $requestPath);
        $link = $href === null ? '' : '<a class="main-nav__link" href="' . $mainNavH($href) . '"'
            . ($isActive ? ' aria-current="page"' : '')
            . ($item['open_in_new_tab'] ? ' target="_blank" rel="' . $mainNavH((string) $item['rel']) . '"' : '')
            . '>' . $mainNavH($item['label']) . '</a>';

        if ($children === []) {
            echo '<li class="main-nav__item">', $link, "</li>\n";
            continue;
        }

        $panelId = 'main-nav-submenu-' . (int) $item['id'];
        $toggleName = str_replace(':label', $item['label'], SiteText::pick(['nl' => 'Submenu :label', 'en' => ':label submenu']));
        ?>
<li class="main-nav__item main-nav__item--has-children">
  <div class="main-nav__row">
<?php if ($href !== null): ?>
    <?= $link ?>
    <button type="button" class="main-nav__toggle" aria-expanded="false" aria-controls="<?= $mainNavH($panelId) ?>" aria-label="<?= $mainNavH($toggleName) ?>"><svg class="main-nav__chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 9l6 6 6-6"/></svg></button>
<?php else: ?>
    <button type="button" class="main-nav__toggle main-nav__toggle--heading" aria-expanded="false" aria-controls="<?= $mainNavH($panelId) ?>"><span><?= $mainNavH($item['label']) ?></span><svg class="main-nav__chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 9l6 6 6-6"/></svg></button>
<?php endif; ?>
  </div>
  <ul class="main-nav__submenu main-nav__submenu--level-<?= $level + 1 ?>" id="<?= $mainNavH($panelId) ?>">
<?php $renderMainNavLevel($children, $level + 1); ?>
  </ul>
</li>
<?php
    }
};
?>
      <ul class="main-nav__list">
<?php $renderMainNavLevel($navItems, 1); ?>
      </ul>
