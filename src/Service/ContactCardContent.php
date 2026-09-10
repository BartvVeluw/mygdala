<?php

namespace App\Service;

use App\Repository\ContactCardRepository;

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

    /** @var array<string, array<string, string>> */
    private static array $cache = [];

    /**
     * @return array<string, string> 'state' (one of STATE_*), title_nl/en,
     *                                body_nl/en, button_label_nl/en and a
     *                                resolved 'button_url'. Templates must
     *                                check 'state' !== STATE_HIDDEN before
     *                                rendering.
     */
    public static function forSection(string $pageSlug, string $sectionKey): array
    {
        $cacheKey = $pageSlug . ':' . $sectionKey;
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

        $content = [
            'title_nl' => (string) ($row['title_nl'] ?? ''),
            'body_nl' => (string) ($row['body_nl'] ?? ''),
            'button_label_nl' => (string) ($row['button_label_nl'] ?? ''),
            'button_url' => self::resolveButtonUrl((string) ($row['button_url'] ?? '')),
        ];

        $content['title_en'] = self::valueOrDefault($row['title_en'] ?? null, $content['title_nl']);
        $content['body_en'] = self::valueOrDefault($row['body_en'] ?? null, $content['body_nl']);
        $content['button_label_en'] = self::valueOrDefault($row['button_label_en'] ?? null, $content['button_label_nl']);
        $content['state'] = self::STATE_ACTIVE;

        return self::$cache[$cacheKey] = $content;
    }

    /**
     * Clears the in-process cache — used by the admin save handler right
     * after writing a new value, and by tests.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
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

    private static function valueOrDefault(?string $value, string $default): string
    {
        return ($value !== null && $value !== '') ? $value : $default;
    }

    /**
     * @return array<string, string>
     */
    private static function emptyContent(): array
    {
        return [
            'title_nl' => '', 'title_en' => '',
            'body_nl' => '', 'body_en' => '',
            'button_label_nl' => '', 'button_label_en' => '',
            'button_url' => '',
        ];
    }
}
