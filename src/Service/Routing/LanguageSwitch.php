<?php

declare(strict_types=1);

namespace App\Service\Routing;

use App\Service\Language\SiteLanguage;
use App\Service\Language\SiteLanguages;

/**
 * The public language switch, as data (docs/multilingual/ROUTING.md).
 *
 * WHAT CHANGED IN PHASE 6. The switch used to be two buttons and a script:
 * one URL held both languages, assets/js/core.js swapped every `data-nl` /
 * `data-en` pair in place, and the choice lived in localStorage. It is now
 * ORDINARY LINKS to real, separate URLs — because that is what a language
 * that has its own URL means, and because a text swap in the browser can
 * never change a canonical tag, an hreflang, a sitemap entry or what a
 * crawler sees.
 *
 * Making them links is also the compatibility boundary: core.js only binds to
 * `.lang-switch button`, so the moment the switch stops rendering buttons the
 * old client-side swap stops running, stops reading localStorage and stops
 * being able to put a page back into the wrong language. The `data-nl` /
 * `data-en` attributes stay in the markup until phase 7 removes them; nothing
 * acts on them any more.
 *
 * AN UNAVAILABLE LANGUAGE IS SHOWN, NOT HIDDEN, and carries no href
 * (App\Service\Routing\LanguageAlternates). A page that has no German version
 * must not offer a German link that 404s, and must not quietly send a visitor
 * to the Dutch one under a German label — but a switch that silently loses an
 * option is just as confusing as one that lies. So the option stays, disabled.
 *
 * NOTE THE DIFFERENCE WITH AN ORDINARY INTERNAL LINK. A menu item pointing at
 * a page with no version in the current language links to the DEFAULT
 * language's URL, because the visitor asked to go there and landing on a real
 * page beats landing on nothing. The switch is the one place that rule is
 * reversed: "read this page in German" has no honest answer when there is no
 * German page, and inventing one is exactly the SEO problem phase 6 exists to
 * avoid.
 */
final class LanguageSwitch
{
    /**
     * One entry per ACTIVE website language, in the site's own order.
     *
     * @return list<array{code: string, label: string, href: ?string, is_current: bool}>
     */
    public static function items(): array
    {
        $alternates = LanguageAlternates::all();
        $current = RequestLanguage::current();

        $items = [];
        foreach (SiteLanguages::active() as $language) {
            $items[] = [
                'code' => $language->code,
                'label' => self::label($language),
                'href' => $alternates[$language->code] ?? null,
                'is_current' => $language->code === $current,
            ];
        }

        return $items;
    }

    /**
     * Is there anything to switch between?
     *
     * Two or more ACTIVE languages. A site that publishes one renders no
     * switch at all — no empty control, no stray focus stop — which is the
     * same question App\Service\Language\SiteText::showsLanguageSwitch()
     * asked, now answered from the website language registry instead of from
     * the closed V1 pair.
     */
    public static function isAvailable(): bool
    {
        return count(SiteLanguages::active()) > 1;
    }

    /**
     * What a button says. The language's own name is what a speaker
     * recognises — a Dutch visitor looking for English scans for "English",
     * not for "Engels" — but the control is small, so the two-letter code is
     * what is printed and the native name is what it is labelled with.
     */
    private static function label(SiteLanguage $language): string
    {
        return strtoupper($language->code);
    }

    /** The accessible name of one entry: its own name, in its own language. */
    public static function accessibleName(string $code): string
    {
        $language = SiteLanguages::find($code);

        if ($language === null) {
            return strtoupper($code);
        }

        return $language->nativeName !== '' ? $language->nativeName : $language->name;
    }
}
