<?php

namespace App\Service;

use App\Repository\PageRepository;

/**
 * The one call-to-action button in the shared site header — the "Vraag
 * offerte aan" that used to be four hardcoded attributes and a literal
 * /contact.php in partials/header.php.
 *
 * Deliberately ONE button, not a region: the header's structure stays
 * Core's (skip link, brand, navigation, language switch, module slots), and
 * an editor configures this button's visibility, its two labels and where it
 * points. Anything more would be a layout builder, which this CMS is not.
 *
 * WHERE IT POINTS is not a second link model. The stored settings have
 * exactly the shape App\Service\LinkResolver already reads for nav items and
 * footer links — link_type plus one companion field — so a CTA can point at
 * a CMS page, a registered application route or an external URL, and gets
 * the same guarantees for free:
 *
 *   - a page that was unpublished or deleted stops resolving;
 *   - a page served by a switched-off module stops resolving;
 *   - a route of a switched-off module is not in App\Service\RouteRegistry
 *     at all, so it stops resolving.
 *
 * "Stops resolving" means forHeader() returns null and the header renders no
 * button — never a link into a 404. The stored setting is left alone;
 * turning the module back on brings the button back. adminWarning() is the
 * other half of that: the editor is told, on the settings screen, that the
 * target they saved cannot currently be reached.
 *
 * Static with a fallback, same convention as SiteSettings/NavigationService/
 * FooterService: a problem here hides one button, it never breaks a page.
 */
class HeaderCta
{
    /** The target kinds a CTA may use; a closed subset of LinkResolver's. */
    public const LINK_TYPES = ['page', 'route', 'external'];

    /**
     * The button as partials/header.php needs it, or null when there is
     * nothing safe to render.
     *
     * @return array{label_nl: string, label_en: string, href: string, open_in_new_tab: bool, rel: ?string}|null
     */
    public static function forHeader(?PageRepository $pageRepository = null): ?array
    {
        if (!self::isEnabled()) {
            return null;
        }

        $labelNl = trim(SiteSettings::get('header_cta_label_nl'));
        if ($labelNl === '') {
            // A button with no label is not a button. This is also what a
            // fresh install has, which is why its code default is empty
            // rather than somebody else's copy.
            return null;
        }

        $resolved = self::resolveTarget($pageRepository);
        if ($resolved === null || $resolved['href'] === null) {
            return null;
        }

        $labelEn = trim(SiteSettings::get('header_cta_label_en'));

        return [
            'label_nl' => $labelNl,
            // Empty EN means "same as NL" — the bilingual fallback this
            // project resolves server-side (see SiteSettings' note on
            // related_products_heading_en). The frontend language switch
            // only falls back when the data-en attribute is ABSENT, so an
            // empty stored value must never reach the template as one.
            'label_en' => $labelEn === '' ? $labelNl : $labelEn,
            'href' => $resolved['href'],
            'open_in_new_tab' => $resolved['open_in_new_tab'],
            'rel' => $resolved['rel'],
        ];
    }

    public static function isEnabled(): bool
    {
        return SiteSettings::get('header_cta_enabled') === '1';
    }

    /**
     * The stored target in the row shape App\Service\LinkResolver reads.
     *
     * @return array<string, mixed>
     */
    public static function targetRow(): array
    {
        $linkType = SiteSettings::get('header_cta_link_type');
        $pageId = trim(SiteSettings::get('header_cta_target_page_id'));

        return [
            'link_type' => in_array($linkType, self::LINK_TYPES, true) ? $linkType : 'page',
            'target_page_id' => $pageId === '' ? null : (int) $pageId,
            'target_route' => SiteSettings::get('header_cta_target_route'),
            'external_url' => SiteSettings::get('header_cta_external_url'),
            'action_key' => null,
            'open_in_new_tab' => SiteSettings::get('header_cta_open_in_new_tab') === '1',
        ];
    }

    /**
     * @return array{href: ?string, open_in_new_tab: bool, rel: ?string, is_action: bool, action_key: ?string}|null
     */
    public static function resolveTarget(?PageRepository $pageRepository = null): ?array
    {
        return LinkResolver::resolve(self::targetRow(), $pageRepository);
    }

    /**
     * What the admin screen must warn about, or null when all is well: a
     * saved target that currently resolves to nothing. Worth saying out loud
     * precisely because the setting is NOT deleted — switching the Shop back
     * on, or republishing the page, restores the button by itself.
     */
    public static function adminWarning(?PageRepository $pageRepository = null): ?string
    {
        if (!self::isEnabled()) {
            return null;
        }

        if (trim(SiteSettings::get('header_cta_label_nl')) === '') {
            return 'De knop staat aan, maar heeft geen Nederlandse tekst en wordt daarom niet getoond.';
        }

        $resolved = self::resolveTarget($pageRepository);
        if ($resolved === null || $resolved['href'] === null) {
            return 'De knop staat aan, maar de gekozen bestemming is nu niet beschikbaar '
                . '(pagina verwijderd of op concept gezet, of een route van een uitgeschakeld onderdeel). '
                . 'De knop wordt daarom niet getoond. De instelling blijft bewaard.';
        }

        return null;
    }
}
