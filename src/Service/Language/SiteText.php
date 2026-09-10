<?php

declare(strict_types=1);

namespace App\Service\Language;

/**
 * How a public partial prints one piece of editor-supplied text.
 *
 * Before Multilingual V1 every partial in this project wrote the same three
 * things by hand:
 *
 *     data-nl="<Dutch>" data-en="<English>"><Dutch>
 *
 * The visible half of that is the bug this class fixes. It assumed Dutch is
 * always the language a visitor should see first, which stopped being true
 * the moment a site could be English-primary (Part F of Multilingual V1).
 * ::visible() asks the site instead of assuming.
 *
 * WHAT DID NOT CHANGE, on purpose: the attribute NAMES are still the language
 * codes, assets/js/core.js still swaps them in the browser, and both
 * languages still live on one URL. Localized URLs and hreflang are
 * deliberately deferred to a later step (MULTILINGUAL.md, "What V1 does not
 * do"), so nothing about routing, canonicals or the sitemap moves here.
 *
 * On a Dutch-primary site — every existing installation, and the default —
 * this class produces byte-for-byte what the old markup produced.
 */
final class SiteText
{
    /**
     * The text a visitor sees before they touch the language switch: the
     * primary language's words, or the other language's when the primary has
     * none.
     *
     * The second half matters more than it looks. An English-primary site
     * whose editor has filled only the Dutch column would otherwise render an
     * empty heading, and "render the other language" beats "render nothing"
     * every time.
     */
    public static function visible(?string $nl, ?string $en): string
    {
        return LocalizedValue::ofDutchEnglish($nl, $en)->primaryValue();
    }

    /**
     * The ` data-nl="..." data-en="..."` pair, escaped and ready to print
     * inside a tag.
     *
     * Both halves are resolved, so neither attribute is ever empty while the
     * other has text — that is what stops the language switch from blanking a
     * heading that simply has no translation yet.
     */
    public static function attrs(?string $nl, ?string $en): string
    {
        $value = LocalizedValue::ofDutchEnglish($nl, $en);

        return ' data-nl="' . self::escape($value->in(LanguageRegistry::DUTCH)) . '"'
            . ' data-en="' . self::escape($value->in(LanguageRegistry::ENGLISH)) . '"';
    }

    /**
     * The same pair for one of the attribute families core.js also swaps:
     * 'alt', 'aria', 'placeholder' or 'content'.
     */
    public static function attrsFor(string $kind, ?string $nl, ?string $en): string
    {
        $value = LocalizedValue::ofDutchEnglish($nl, $en);
        $suffix = '-' . $kind;

        return ' data-nl' . $suffix . '="' . self::escape($value->in(LanguageRegistry::DUTCH)) . '"'
            . ' data-en' . $suffix . '="' . self::escape($value->in(LanguageRegistry::ENGLISH)) . '"';
    }

    /**
     * The language code a page's <html lang> should carry, and the one
     * assets/js/core.js starts in when a visitor has expressed no preference.
     */
    public static function documentLanguage(): string
    {
        return ContentLanguages::primary();
    }

    /**
     * Does this site offer a language switch at all?
     *
     * A single-language site renders none — two buttons that both mean the
     * same thing are furniture, and one of them would have shown a visitor
     * the same page in the same words.
     */
    public static function showsLanguageSwitch(): bool
    {
        return ContentLanguages::isMultilingual();
    }

    /** @return string[] the codes a visitor may switch between, primary first */
    public static function switchableLanguages(): array
    {
        return ContentLanguages::enabled();
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
