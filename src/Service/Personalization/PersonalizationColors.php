<?php

declare(strict_types=1);

namespace App\Service\Personalization;

use App\Service\Language\LanguageFallback;
use App\Service\Language\SiteText;

/**
 * The fixed palette a customer may preview their engraving text in.
 *
 * Deliberately a small, closed list in code — not a colour picker, and not a
 * value the browser may invent. Two reasons:
 *
 *   1. It is a DISPLAY choice, not a production instruction. A laser engraves
 *      what the material gives you; the point of this control is that the
 *      customer can read their own text against a dark walnut photo and
 *      against a pale birch one. A free picker would suggest a promise the
 *      workshop cannot keep.
 *   2. The chosen value ends up in CSS (`color:`) on the product page and in
 *      the CMS order screen. Accepting an arbitrary string there is how a
 *      style attribute turns into an injection surface. Everything that
 *      leaves this class is a hex literal this file wrote.
 *
 * The KEY is what travels and what is stored on the order line — never the
 * hex. A key keeps meaning the same thing years later even if the hex behind
 * it is nudged, exactly like a font key (see PersonalizationFonts).
 */
class PersonalizationColors
{
    /**
     * Stable key => customer-facing label + the hex it renders as.
     *
     * The label is a small code catalogue keyed by language code, read like
     * every other piece of code-owned website text
     * (App\Service\Language\SiteText::pick()): the request's language, else
     * the default language, else the first.
     *
     * Chosen to stay legible on both light and dark product photos: two
     * near-extremes, a mid grey, and two warm tones that read as engraving
     * rather than as ink.
     *
     * @var array<string, array{label: array<string, string>, hex: string}>
     */
    private const COLORS = [
        'black' => ['label' => ['nl' => 'Zwart', 'en' => 'Black'], 'hex' => '#1B140D'],
        'dark_grey' => ['label' => ['nl' => 'Donkergrijs', 'en' => 'Dark grey'], 'hex' => '#4A443C'],
        'brown' => ['label' => ['nl' => 'Bruin', 'en' => 'Brown'], 'hex' => '#6B4A2B'],
        'gold' => ['label' => ['nl' => 'Goud', 'en' => 'Gold'], 'hex' => '#C9A063'],
        'white' => ['label' => ['nl' => 'Wit', 'en' => 'White'], 'hex' => '#F7F1E6'],
    ];

    /**
     * What a text zone starts on, and what a missing/unknown stored value
     * resolves to. White reads on the dark product photography this shop
     * mostly uses, and is what every order placed before this setting existed
     * was actually previewed in — so an old order keeps looking the way it
     * looked.
     */
    public const FALLBACK = 'white';

    /** @return list<string> every valid key, in palette order */
    public static function keys(): array
    {
        return array_keys(self::COLORS);
    }

    public static function isValid(mixed $key): bool
    {
        return is_string($key) && isset(self::COLORS[$key]);
    }

    /**
     * A colour's name in one language — the request's when none is named —
     * else the default language's, else the catalogue's first; the key
     * itself for an unknown key.
     */
    public static function label(string $key, ?string $languageCode = null): string
    {
        return isset(self::COLORS[$key]) ? SiteText::pick(self::COLORS[$key]['label'], $languageCode) : $key;
    }

    /**
     * The hex for a key — always one of this file's own literals, never
     * anything derived from input. An unknown key falls back rather than
     * returning something unusable, because a text layer always has to render
     * in SOME colour.
     */
    public static function hex(string $key): string
    {
        return self::COLORS[$key]['hex'] ?? self::COLORS[self::FALLBACK]['hex'];
    }

    /**
     * THE authorisation decision for a submitted colour, as a pure function.
     * Anything that is not a key in this palette becomes the fallback: a
     * colour is a preview preference and is never worth failing a checkout
     * over, and silently correcting it is safe precisely because the result
     * can only ever be one of five values.
     */
    public static function resolveSubmitted(mixed $submitted): string
    {
        return self::isValid($submitted) ? (string) $submitted : self::FALLBACK;
    }

    /**
     * The palette in the shape the browser wants: every label in the
     * request's language.
     *
     * @return list<array{key: string, label: string, hex: string}>
     */
    public static function payload(): array
    {
        $payload = [];
        foreach (self::COLORS as $key => $color) {
            $payload[] = ['key' => $key, 'label' => self::label($key), 'hex' => $color['hex']];
        }

        return $payload;
    }

    /**
     * One colour as it is recorded on an order line: the key plus the label
     * and hex that key meant AT THAT MOMENT. Copied for the same reason the
     * font's label and stack are copied — so a later palette change cannot
     * rewrite what a placed order says the customer chose. The label is the
     * website's default language's: a record for the shop owner, the same
     * rule the view and zone names in the snapshot follow.
     *
     * @return array{key: string, label: string, hex: string}
     */
    public static function snapshot(string $key): array
    {
        return [
            'key' => $key,
            'label' => self::label($key, LanguageFallback::defaultLanguage()),
            'hex' => self::hex($key),
        ];
    }
}
