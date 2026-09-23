<?php

declare(strict_types=1);

use App\Service\AssetVersion;

/**
 * Tabs for a long admin screen.
 *
 * THE PROBLEM IT SOLVES. Some admin screens are one vertical stack of
 * unrelated cards — the page editor is page settings, then SEO, then the
 * whole content of the page; Instellingen is five independent forms.
 * An editor who wants the third thing scrolls past the first two every time.
 *
 * WHAT IT IS NOT. It is not a framework and it moves no data. A tab is a
 * <div> around cards that were already there, shown or hidden. Every form
 * inside keeps its own action, its own validation and its own endpoint, and
 * a field never changes which form it belongs to by moving into a tab. In
 * particular: a form may span two panels (admin/page.php's Pagina and SEO
 * panels are one <form> to one endpoint), because splitting it would turn
 * one save into two partial POSTs.
 *
 * HOW A SCREEN USES IT
 *
 *     require_once __DIR__ . '/_admin_tabs.php';
 *     ...
 *     <?php admin_tabs_start('page-editor', ['inhoud' => 'Inhoud', ...], [
 *         'scope' => (string) $pageId,
 *         'label' => 'Onderdelen van deze pagina',
 *     ]); ?>
 *       <?php admin_tab_panel('inhoud'); ?>
 *         ... cards ...
 *       <?php admin_tab_panel_end(); ?>
 *       ...
 *     <?php admin_tabs_end(); ?>
 *     ...
 *     <?php admin_tabs_script(); ?>
 *
 * WHY THE PANELS ARE BUFFERED. The tab strip is printed above the panels but
 * can only be written once every panel id is known — a tab may control more
 * than one panel (admin/page.php's "Verwijderen" card is a second Pagina
 * panel, because it holds a <form> and could therefore not sit inside the
 * page-settings form). admin_tabs_start() opens an output buffer, each panel
 * registers its id while it renders, and admin_tabs_end() prints the strip
 * with a complete aria-controls and then the buffered panels.
 *
 * WITHOUT JAVASCRIPT the tab strip is not displayed at all and every panel
 * stays visible — the screen is then exactly the long stack it used to be,
 * which is the honest fallback for a navigation aid. The inactive panels are
 * hidden by the SERVER (so a screen never flashes its whole stack before the
 * deferred script runs) and put back by a <noscript> rule; admin-tabs.js
 * marks the container as enhanced, and only then does the strip appear.
 *
 * STATE. Which tab is open is remembered per group AND per scope in
 * sessionStorage, so returning to a screen after a save lands where you were
 * and page 12 never restores page 7's tab. A screen may override that for
 * one render with the `force` option — a failed save whose messages live on
 * one tab must open that tab, whatever was stored.
 */

/**
 * The open tab groups, innermost last. A stack rather than a single slot so
 * a screen can render two groups in a row (and, in principle, nest them)
 * without one clearing the other's registrations.
 *
 * @return array<int, array<string, mixed>>
 */
function &admin_tabs_stack(): array
{
    static $stack = [];

    return $stack;
}

function admin_tabs_slug(string $value): string
{
    $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $value) ?? '');

    return trim($slug, '-');
}

/**
 * Open a tab group. Everything printed until admin_tabs_end() is buffered.
 *
 * @param string                $group  identity of this tab strip, e.g. "page-editor"
 * @param array<string, string> $tabs   panel key => the label on its tab
 * @param array{scope?: string, label?: string, default?: string, force?: ?string} $options
 */
function admin_tabs_start(string $group, array $tabs, array $options = []): void
{
    if ($tabs === []) {
        return;
    }

    $group = admin_tabs_slug($group);
    $keys = array_keys($tabs);
    $default = (string) ($options['default'] ?? $keys[0]);
    $force = $options['force'] ?? null;

    if (!array_key_exists($default, $tabs)) {
        $default = (string) $keys[0];
    }

    if ($force !== null && !array_key_exists($force, $tabs)) {
        $force = null;
    }

    $stack = &admin_tabs_stack();
    $stack[] = [
        'group' => $group,
        'tabs' => $tabs,
        'scope' => (string) ($options['scope'] ?? ''),
        'label' => (string) ($options['label'] ?? 'Onderdelen'),
        'default' => $default,
        'force' => $force,
        'panels' => [],
    ];

    ob_start();
}

/**
 * Open one panel. Called once per tab, or more than once for a tab whose
 * content cannot be a single element (see the file docblock).
 */
function admin_tab_panel(string $key): void
{
    $stack = &admin_tabs_stack();
    $index = count($stack) - 1;

    if ($index < 0 || !array_key_exists($key, $stack[$index]['tabs'])) {
        return;
    }

    $group = $stack[$index]['group'];
    $slug = admin_tabs_slug($key);
    $seen = $stack[$index]['panels'][$key] ?? [];
    $id = 'admin-tab-' . $group . '-' . $slug . ($seen === [] ? '' : '-' . (count($seen) + 1));
    $stack[$index]['panels'][$key][] = $id;

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    // Hidden from the server rather than by the script, so a screen does not
    // flash its whole stack before the deferred script runs. The <noscript>
    // rule in admin.css puts every panel back for a browser that will never
    // run the script at all.
    $initial = $stack[$index]['force'] ?? $stack[$index]['default'];

    echo '<div class="admin-tabs__panel" role="tabpanel"'
        . ' id="' . $h($id) . '"'
        . ' data-admin-tab-panel="' . $h($key) . '"'
        . ' aria-labelledby="' . $h('admin-tabbtn-' . $group . '-' . $slug) . '"'
        . ((string) $key === (string) $initial ? '' : ' hidden')
        . '>';
}

function admin_tab_panel_end(): void
{
    echo '</div>';
}

/**
 * Close the group: print the tab strip, then the panels that were buffered.
 */
function admin_tabs_end(): void
{
    $stack = &admin_tabs_stack();

    if ($stack === []) {
        return;
    }

    $state = array_pop($stack);
    $panelsHtml = (string) ob_get_clean();

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $group = (string) $state['group'];

    $initial = $state['force'] ?? $state['default'];

    echo '<div class="admin-tabs" data-admin-tabs="' . $h($group) . '"'
        . ' data-admin-tabs-scope="' . $h((string) $state['scope']) . '"'
        . ' data-admin-tabs-default="' . $h((string) $state['default']) . '"'
        . ($state['force'] !== null ? ' data-admin-tabs-force="' . $h((string) $state['force']) . '"' : '')
        . '>';

    // Scripting off: no tab strip, and every panel back on screen. The
    // contents of a <noscript> are not parsed at all while scripting is on,
    // so this rule can never affect a working browser.
    echo '<noscript><style>.admin-tabs__panel[hidden]{display:block !important}.admin-tabs__list{display:none !important}</style></noscript>';

    echo '<div class="admin-tabs__list" role="tablist" aria-label="' . $h((string) $state['label']) . '">';

    foreach ($state['tabs'] as $key => $label) {
        $slug = admin_tabs_slug((string) $key);
        $controls = implode(' ', $state['panels'][$key] ?? []);
        $isInitial = (string) $key === (string) $initial;

        echo '<button type="button" role="tab" class="admin-tabs__tab' . ($isInitial ? ' is-active' : '') . '"'
            . ' id="' . $h('admin-tabbtn-' . $group . '-' . $slug) . '"'
            . ' data-admin-tab="' . $h((string) $key) . '"'
            . ($controls !== '' ? ' aria-controls="' . $h($controls) . '"' : '')
            . ' aria-selected="' . ($isInitial ? 'true' : 'false') . '"'
            . ' tabindex="' . ($isInitial ? '0' : '-1') . '">'
            . '<span class="admin-tabs__label">' . $h((string) $label) . '</span>'
            . '</button>';
    }

    echo '</div>';
    echo $panelsHtml;
    echo '</div>';
}

/**
 * The behaviour. Separate from admin.js for the same reason the save bar and
 * the media picker are: a screen loads what it renders.
 */
function admin_tabs_script(): void
{
    echo '<script src="' . htmlspecialchars(AssetVersion::url('/admin/assets/admin-tabs.js'), ENT_QUOTES, 'UTF-8') . '" defer></script>';
}
