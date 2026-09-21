<?php

namespace App\Service;

use App\Repository\ContactCardRepository;
use App\Service\Blocks\BlockLocalization;
use App\Service\Routing\RequestLanguage;

/**
 * Content for the "Contactkaart" block (partials/section-contact-card.php) —
 * a small card with a heading, a short text and one button. It started life
 * as the Contact page's hardcoded "Liever direct mailen?" card and became a
 * repeatable, configurable block in phase 2 of
 * docs/content-blocks/ROADMAP.md.
 *
 * The button URL is optional, and empty means "mail me": the block then
 * links to `mailto:` + the e-mail address from Site-instellingen. That is
 * not a special case for one page — it is this block type's documented
 * default, and it is what keeps the migrated card following the site's
 * e-mail address the way the hardcoded markup did.
 *
 * WORDS PER LANGUAGE (Multilingual 2.0 phase 3A). The heading, the text and
 * the button label are stored per website language in block_translations and
 * come out of App\Service\Blocks\BlockLocalization as one string each, in
 * the language of the request, the fallback already applied; the URL and
 * is_active stay in contact_cards, the same in every language. This class
 * decides no language.
 *
 * `is_active = false` on an existing row is a deliberate hide, and a
 * different case from a missing row — the same three-state contract
 * (STATE_*) every other block Content class in this project uses.
 */
class ContactCardContent
{
    /** No row exists (or the row lookup failed) — nothing to render. */
    public const STATE_FALLBACK = 'fallback';

    /** A row exists and is_active = true — rendering its own content. */
    public const STATE_ACTIVE = 'active';

    /** A row exists and is_active = false — an intentional hide; render nothing. */
    public const STATE_HIDDEN = 'hidden';

    /**
     * The section_key the Contact page's original, hardcoded mail card was
     * migrated onto. Instances added afterwards get a random
     * custom-xxxxxxxx key like every other repeater type.
     */
    public const MIGRATED_SECTION_KEY = 'main';

    /** The translatable fields of this block (ContactCardBlock::translatableFields()). */
    public const WORDS = ['title', 'body', 'button_label'];

    private const TABLE = 'contact_cards';

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * @return array<string, mixed> 'state' (one of STATE_*), a string
     *                              per field in WORDS and a resolved string
     *                              'button_url'. Templates must check
     *                              'state' !== STATE_HIDDEN before rendering.
     */
    public static function forSection(string $pageSlug, string $sectionKey): array
    {
        $cacheKey = RequestLanguage::current() . '|' . $pageSlug . ':' . $sectionKey;
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        try {
            $row = (new ContactCardRepository())->findBySlugAndKey($pageSlug, $sectionKey);
        } catch (\Throwable $e) {
            error_log('[ContactCardContent] lookup failed for "' . $cacheKey . '": ' . $e->getMessage());
            $row = null;
        }

        if ($row === null) {
            return self::$cache[$cacheKey] = self::emptyContent() + ['state' => self::STATE_FALLBACK];
        }

        if (!(bool) $row['is_active']) {
            return self::$cache[$cacheKey] = self::emptyContent() + ['state' => self::STATE_HIDDEN];
        }

        $cardId = (int) $row['id'];

        // THE DEFAULT LANGUAGE DECIDES WHETHER THE CARD SAYS ANYTHING
        // (docs/multilingual/ARCHITECTURE.md): without a heading and without
        // a text in the default language there is no card in any language.
        $hasWords = BlockLocalization::hasDefaultWords(self::TABLE, $cardId, 'title')
            || BlockLocalization::hasDefaultWords(self::TABLE, $cardId, 'body');

        $content = [];
        foreach (self::WORDS as $field) {
            $content[$field] = $hasWords ? BlockLocalization::text(self::TABLE, $cardId, $field) : '';
        }

        // The button, too, needs its label in the default language.
        if (!BlockLocalization::hasDefaultWords(self::TABLE, $cardId, 'button_label')) {
            $content['button_label'] = '';
        }

        $content['button_url'] = self::resolveButtonUrl((string) ($row['button_url'] ?? ''));
        $content['state'] = self::STATE_ACTIVE;

        return self::$cache[$cacheKey] = $content;
    }

    /**
     * Clears the in-process cache, and the block words BlockLocalization
     * holds — used by the admin save handler right after writing a new
     * value, and by tests.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
        BlockLocalization::clearCache();
    }

    /**
     * An empty stored URL means "mail me at the address in
     * Site-instellingen" — the documented default of this block type. An
     * address that is missing there leaves the URL empty, and the partial
     * then renders the card without a button rather than a dead `mailto:`.
     */
    private static function resolveButtonUrl(string $stored): string
    {
        if ($stored !== '') {
            return $stored;
        }

        $email = trim((string) SiteSettings::get('email'));

        return $email === '' ? '' : 'mailto:' . $email;
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyContent(): array
    {
        $content = [];
        foreach (self::WORDS as $field) {
            $content[$field] = '';
        }

        return $content + ['button_url' => ''];
    }
}
