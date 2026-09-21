<?php

namespace App\Service;

use App\Repository\FormBlockRepository;
use App\Service\Blocks\BlockLocalization;
use App\Service\Routing\RequestLanguage;

/**
 * Content for the reusable "Formulier" block (partials/section-form.php):
 * which form to show, plus the heading and introduction of the block around
 * it.
 *
 * The FIELDS are deliberately not here. A form is defined once under Beheer
 * → Formulieren and placed by as many blocks as an editor likes; this class
 * only answers "which one, and what does the block say above it". That split
 * is the whole reason Core Forms exists (FORMS.md).
 *
 * WORDS PER LANGUAGE (Multilingual 2.0 phase 3B). The heading and the
 * introduction are stored per website language in block_translations and come
 * out of App\Service\Blocks\BlockLocalization as one string each, in the
 * language of the request, the fallback already applied; which form, and
 * is_active, stay in form_blocks. The form's own words (labels, button,
 * confirmation) belong to the form, not to this block. This class decides no
 * language itself.
 *
 * `is_active = false` on an existing row is a deliberate hide, and a
 * different case from a missing row — the same three-state contract every
 * other block Content class in this project uses (CONTENT-BLOCKS.md).
 */
class FormBlockContent
{
    /** No row exists (or the row lookup failed) — nothing to render. */
    public const STATE_FALLBACK = 'fallback';

    /** A row exists and is_active = true — rendering its own content. */
    public const STATE_ACTIVE = 'active';

    /** A row exists and is_active = false — an intentional hide; render nothing. */
    public const STATE_HIDDEN = 'hidden';

    /** The owner table of this block's words (FormBlock::translatableFields()). */
    private const TABLE = 'form_blocks';

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * @return array<string, mixed> 'state' (one of STATE_*), form_id (int|null),
     *                              and title and intro (a string each,
     *                              either may be empty). Templates must check
     *                              'state' !== STATE_HIDDEN before rendering.
     */
    public static function forSection(string $pageSlug, string $sectionKey): array
    {
        $cacheKey = RequestLanguage::current() . '|' . $pageSlug . ':' . $sectionKey;
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        try {
            $row = (new FormBlockRepository())->findBySlugAndKey($pageSlug, $sectionKey);
        } catch (\Throwable $e) {
            error_log('[FormBlockContent] lookup failed for "' . $cacheKey . '": ' . $e->getMessage());
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
            'form_id' => $row['form_id'] === null ? null : (int) $row['form_id'],
        ] + BlockLocalization::words(self::TABLE, (int) $row['id']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function empty(string $state): array
    {
        return [
            'state' => $state,
            'form_id' => null,
        ] + BlockLocalization::words(self::TABLE, 0);
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
