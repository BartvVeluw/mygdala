<?php

declare(strict_types=1);

require_once __DIR__ . '/_translate.php';

use App\Service\LinkResolver;
use App\Service\PageContent;

/**
 * Where a menu item, header button or footer link goes, in the editor's
 * words, and whether that destination is on the website right now. One
 * helper for Header & navigatie (admin/navigation.php) and Footer
 * (admin/footer.php), because both store the same link shape and resolve it
 * with the same App\Service\LinkResolver (HEADER-FOOTER.md): the two lists
 * must never describe the same kind of link differently.
 *
 * NOT HERE: validating a link before it is stored. That is
 * LinkResolver::validate(), called by api/admin/_nav_item_input.php and
 * api/admin/_footer_link_input.php.
 */

/**
 * A page by its current title, a fixed part of the site by its registry
 * label, another address as typed, the cookie-settings action by name.
 *
 * @param array<string, mixed>                $row       a nav_items or footer_links row
 * @param array<int, array<string, mixed>>    $pagesById every page, keyed by id
 * @param array<string, array<string, mixed>> $routes    App\Service\RouteRegistry::all()
 */
function admin_link_destination_summary(array $row, array $pagesById, array $routes): string
{
    $summary = match ((string) $row['link_type']) {
        'page' => isset($pagesById[(int) ($row['target_page_id'] ?? 0)])
            ? admin_t('navigation.destination_page', ['page' => (string) $pagesById[(int) $row['target_page_id']]['title']])
                . (PageContent::isPublished($pagesById[(int) $row['target_page_id']]) ? '' : ' ' . admin_t('navigation.destination_draft'))
            : admin_t('navigation.destination_page_missing'),
        'route' => isset($routes[(string) $row['target_route']])
            ? admin_t('navigation.destination_route', ['route' => (string) $routes[(string) $row['target_route']]['label_nl']])
            : admin_t('navigation.destination_route_off'),
        'external' => admin_t('navigation.destination_external', ['url' => (string) $row['external_url']]),
        'none' => admin_t('navigation.destination_none'),
        'action' => admin_t('footer.destination_action'),
        default => admin_t('navigation.destination_unknown'),
    };

    if (!empty($row['open_in_new_tab']) && in_array((string) $row['link_type'], ['page', 'route', 'external'], true)) {
        $summary .= ' · ' . admin_t('navigation.opens_in_new_tab');
    }

    return $summary;
}

/**
 * Is this row's destination reachable right now, as the public header and
 * footer see it? A submenu heading has no destination and is always fine.
 *
 * @param array<string, mixed> $row
 */
function admin_link_is_reachable(array $row): bool
{
    return (string) $row['link_type'] === 'none' || LinkResolver::resolve($row) !== null;
}
