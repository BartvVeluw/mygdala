<?php

namespace App\Service;

use App\Repository\FooterRepository;
use App\Service\Language\LocalizedValue;

/**
 * Public read side of the CMS-managed footer — replaces the hardcoded
 * columns in partials/footer.php. Composes footer_columns/footer_links
 * (via App\Repository\FooterRepository + App\Service\LinkResolver) with the
 * Brand/Company block, which is deliberately NOT stored here: it reads
 * SiteSettings directly (site_name/logo_path/email/company_phone/
 * kvk_number; the description through App\Service\LocalizedSiteSettings)
 * plus this class's own small
 * footer_show_... / footer_copyright_template settings — see
 * db/migrations/20260907230000_add_footer_settings.php. One source of
 * truth for company data; this class only ever decides *whether* to show
 * each field, never stores a second copy of it.
 *
 * WORDS. A column's title and a link's label arrive as one
 * App\Service\Language\LocalizedValue each, from
 * App\Service\FooterLocalization, loaded for the whole footer in two
 * queries. Neither this class nor partials/footer.php decides a language.
 *
 * Static, try/catch-with-fallback, same convention as NavigationService —
 * a footer problem must never break every public page.
 */
class FooterService
{
    /**
     * @return list<array{id:int,title:\App\Service\Language\LocalizedValue,links:list<array<string,mixed>>}>
     */
    public static function columns(): array
    {
        try {
            $repository = new FooterRepository();
            $columns = $repository->findVisibleColumnsForPublic();
            $allLinks = $repository->findAllVisibleLinks();
            FooterLocalization::preload(
                array_map(static fn (array $column): int => (int) $column['id'], $columns),
                array_map(static fn (array $link): int => (int) $link['id'], $allLinks)
            );
            // The addresses of every linked page, in one query rather than
            // one per page (App\Service\LinkResolver::preloadPageAddresses()).
            LinkResolver::preloadPageAddresses($allLinks);
        } catch (\Throwable $e) {
            error_log('[FooterService] falling back to empty footer columns: ' . $e->getMessage());
            return [];
        }

        $linksByColumn = [];
        foreach ($allLinks as $link) {
            $linksByColumn[(int) $link['column_id']][] = $link;
        }

        $result = [];
        foreach ($columns as $column) {
            $columnLinks = [];
            foreach ($linksByColumn[(int) $column['id']] ?? [] as $link) {
                $resolved = LinkResolver::resolve($link);
                if ($resolved === null) {
                    continue;
                }

                $columnLinks[] = [
                    'id' => (int) $link['id'],
                    'label' => FooterLocalization::linkLabel((int) $link['id']),
                    'href' => $resolved['href'],
                    'open_in_new_tab' => $resolved['open_in_new_tab'],
                    'rel' => $resolved['rel'],
                    'is_action' => $resolved['is_action'],
                    'action_key' => $resolved['action_key'],
                ];
            }

            if ($columnLinks === []) {
                // A visible column with nothing resolvable left to show
                // (every link hidden/broken) renders no heading either.
                continue;
            }

            $result[] = [
                'id' => (int) $column['id'],
                'title' => FooterLocalization::columnTitle((int) $column['id']),
                'links' => $columnLinks,
            ];
        }

        return $result;
    }

    /**
     * @return array{show_logo:bool,show_company_name:bool,show_email:bool,show_phone:bool,show_kvk:bool}
     */
    public static function brandSettings(): array
    {
        return [
            'show_logo' => SiteSettings::get('footer_show_logo') === '1',
            'show_company_name' => SiteSettings::get('footer_show_company_name') === '1',
            'show_email' => SiteSettings::get('footer_show_email') === '1',
            'show_phone' => SiteSettings::get('footer_show_phone') === '1',
            'show_kvk' => SiteSettings::get('footer_show_kvk') === '1',
        ];
    }

    /**
     * The footer's closing line, or null when there is nothing to show —
     * switched off, or switched on with no words in the website's DEFAULT
     * language. It used to be a literal in partials/footer.php ("Ontworpen &
     * gebouwd met zorg in Nijmegen"), which is this site's own copy and not
     * something a second installation should inherit.
     *
     * The words are App\Service\LocalizedSiteSettings's, one value per
     * website language, and arrive as one LocalizedValue with the fallback
     * already applied, so an untranslated line never reaches the page empty.
     * The default language decides whether the line exists at all, as it did
     * when that was the Dutch value: a translation alone shows nothing.
     */
    public static function slogan(): ?LocalizedValue
    {
        if (SiteSettings::get('footer_slogan_enabled') !== '1'
            || !LocalizedSiteSettings::hasDefault(LocalizedSiteSettings::FOOTER_SLOGAN)
        ) {
            return null;
        }

        return LocalizedSiteSettings::bilingual(LocalizedSiteSettings::FOOTER_SLOGAN);
    }

    /**
     * The footer's description in the company block, or null when the
     * website's DEFAULT language has none. The same rule as the closing line:
     * a translation alone never prints a paragraph whose visible words would
     * be empty.
     */
    public static function description(): ?LocalizedValue
    {
        if (!LocalizedSiteSettings::hasDefault(LocalizedSiteSettings::FOOTER_DESCRIPTION)) {
            return null;
        }

        return LocalizedSiteSettings::bilingual(LocalizedSiteSettings::FOOTER_DESCRIPTION);
    }

    /**
     * Resolves the tiny, fixed {{year}}/{{site_name}} placeholder set in the
     * copyright template — never arbitrary text execution, just two
     * str_replace()s against known-safe, already-escaped values.
     */
    public static function renderCopyright(): string
    {
        $template = SiteSettings::get('footer_copyright_template');

        return str_replace(
            ['{{year}}', '{{site_name}}'],
            [date('Y'), SiteSettings::get('site_name')],
            $template
        );
    }
}
