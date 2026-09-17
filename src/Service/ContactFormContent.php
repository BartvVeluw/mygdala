<?php

namespace App\Service;

use App\Repository\ContactFormRepository;
use App\Service\Blocks\BlockLocalization;

/**
 * Content for the "Offerte-/contactformulier" block
 * (partials/section-contact-form.php) — the form card next to the
 * "Direct contact" details card, in one `.contact-grid`.
 *
 * SINCE CORE FORMS THIS BLOCK IS A WRAPPER. The form it shows is an ordinary
 * Form definition (`form_id`), rendered by the same partials/form.php and
 * validated by the same pipeline as every other form on the site — there is
 * no second set of fields, no second validator and no second mail builder
 * (FORMS.md, "Het contactformulier"). What the block still owns is the two
 * things that are not part of a generic form:
 *
 *   - the "Direct contact" card beside it, which shares the block's
 *     two-column grid and renders the e-mail address and workshop city from
 *     Site-instellingen rather than page content;
 *   - the optional file attachment (`allow_attachment`) this site's quote
 *     form has accepted since long before Core Forms existed. Forms V1 has
 *     no upload field and the form builder cannot create one; taking a
 *     working feature away from a live site is not what "generic" means.
 *
 * A new page normally uses the plain "Formulier" block
 * (App\Service\Blocks\FormBlock) instead. This one exists for the pairing
 * with the contact details.
 *
 * `is_active = false` on an existing row is a deliberate hide, and a
 * different case from a missing row — see the STATE_* constants, the same
 * three-state contract every other block Content class in this project uses.
 */
class ContactFormContent
{
    /** No row exists (or the row lookup failed) — nothing to render. */
    public const STATE_FALLBACK = 'fallback';

    /** A row exists and is_active = true — rendering its own content. */
    public const STATE_ACTIVE = 'active';

    /** A row exists and is_active = false — an intentional hide; render nothing. */
    public const STATE_HIDDEN = 'hidden';

    /**
     * The section_key the Contact page's original, hardcoded form was
     * migrated onto. Instances added afterwards get a random
     * custom-xxxxxxxx key like every other repeater type.
     */
    public const MIGRATED_SECTION_KEY = 'main';

    /**
     * The `forms.internal_key` of the Form definition this site's original,
     * hardcoded quote form was migrated onto
     * (db/migrations/20260909310000_migrate_the_contact_form_into_a_form.php).
     *
     * It exists so the legacy endpoint api/contact.php — kept only for a
     * page a visitor still has open from before Core Forms — knows which
     * form an old POST belongs to, without guessing at a page slug. Nothing
     * else depends on it, and a site that never had that form simply does
     * not have this key.
     */
    public const MIGRATED_FORM_KEY = 'contactformulier';

    /** The owner table of this block's words (ContactFormBlock::translatableFields()). */
    private const TABLE = 'contact_form_sections';

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * @return array<string, mixed> 'state' (one of STATE_*), title (a
     *                              LocalizedValue, stored per website
     *                              language in block_translations), form_id
     *                              (int|null) and allow_attachment (bool).
     *                              Templates must check 'state' !==
     *                              STATE_HIDDEN before rendering.
     */
    public static function forSection(string $pageSlug, string $sectionKey): array
    {
        $cacheKey = $pageSlug . ':' . $sectionKey;
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        try {
            $row = (new ContactFormRepository())->findBySlugAndKey($pageSlug, $sectionKey);
        } catch (\Throwable $e) {
            error_log('[ContactFormContent] lookup failed for "' . $cacheKey . '": ' . $e->getMessage());
            $row = null;
        }

        if ($row === null) {
            return self::$cache[$cacheKey] = self::empty(self::STATE_FALLBACK);
        }

        if (!(bool) $row['is_active']) {
            return self::$cache[$cacheKey] = self::empty(self::STATE_HIDDEN);
        }

        return self::$cache[$cacheKey] = [
            'state' => self::STATE_ACTIVE,
            'title' => BlockLocalization::words(self::TABLE, (int) $row['id'])['title'],
            'form_id' => ($row['form_id'] ?? null) === null ? null : (int) $row['form_id'],
            'allow_attachment' => (bool) ($row['allow_attachment'] ?? true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function empty(string $state): array
    {
        return [
            'state' => $state,
            'title' => BlockLocalization::words(self::TABLE, 0)['title'],
            'form_id' => null,
            'allow_attachment' => false,
        ];
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
}
