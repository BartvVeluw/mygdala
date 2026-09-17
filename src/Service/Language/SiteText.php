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
     * The words a visitor sees first, for text that already arrives as ONE
     * value in every language — what App\Service\Blocks\BlockLocalization
     * hands a block partial. Unescaped: the caller escapes plain text, and
     * prints sanitized rich text as it is.
     *
     * A partial built on these three methods never learns which language is
     * the default, where the words are stored or what an empty translation
     * falls back to. That is what lets the frontend flip of Multilingual 2.0
     * change this class instead of every partial.
     */
    public static function visibleOf(LocalizedValue $text): string
    {
        return $text->primaryValue();
    }

    /** The escaped ` data-nl="..." data-en="..."` pair of a plain-text value, for core.js's textContent switch. */
    public static function attrsOf(LocalizedValue $text): string
    {
        $attributes = '';
        foreach ($text->attributeValues() as $code => $value) {
            $attributes .= ' data-' . $code . '="' . self::escape($value) . '"';
        }

        return $attributes;
    }

    /**
     * The pair for SANITIZED rich text, marked `data-lang-html` so core.js
     * re-renders it with innerHTML rather than textContent — the one opt-in
     * the language switch allows (MULTILINGUAL.md). Nothing at all when every
     * language shows the same markup, so a body without a translation prints
     * exactly the element it printed before the switch knew about it.
     *
     * Only ever for RichTextSanitizer output: marking editor plain text
     * data-lang-html would turn the switch into an XSS sink.
     */
    public static function htmlAttrsOf(LocalizedValue $html): string
    {
        $values = $html->attributeValues();

        if (count(array_unique(array_merge(array_values($values), [$html->primaryValue()]))) < 2) {
            return '';
        }

        return ' data-lang-html' . self::attrsOf($html);
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
     * ALWAYS, on the bilingual product this CMS is today, and that is the
     * point of asking it this way rather than asking a settings row.
     *
     * It used to be gated on an "enabled languages" setting, so a site whose
     * owner had not explicitly turned English on showed visitors no switch —
     * including sites that had English content sitting in their `_en`
     * columns. A visitor who wants to read the site in English must always be
     * able to ask for it; a field nobody has translated yet falls back to the
     * primary language's words (App\Service\Language\LocalizedValue), so
     * the switch can never produce a blank page.
     *
     * The question itself stays — a build registering a single content
     * language would rightly render no switch — it is simply answered from
     * what this CMS publishes rather than from a row an owner can get wrong.
     */
    public static function showsLanguageSwitch(): bool
    {
        return count(self::switchableLanguages()) > 1;
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
